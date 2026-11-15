<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Code-oriented file operations for the Claude connector (used by Fs, so they run inside fsworker as the hosting
 * account, confined to the document root): partial edits, ranged reads, search, batch writes, archive extraction,
 * HTTPS downloads, and a per-file version history kept OUTSIDE the document root, so every change can be undone.
 */
trait FsCode
{
    private const HIST_KEEP     = 20;               // versions per file
    private const HIST_MAX_FILE = 50 * 1024 * 1024; // larger files are not versioned
    private const READ_LINES    = 800;
    private const READ_BYTES    = 262144;
    private const BATCH_BYTES   = 16 * 1024 * 1024;
    private const FETCH_MAX     = 1024 * 1024 * 1024;
    private const INCOMING      = '.havoc-incoming';            // upload links put archives here (hidden from the web)

    private function historyDir(): string
    {
        return dirname($this->root) . '/.havoc-history';
    }

    /** Copies the current content of a file into its history; returns the version id (null = nothing to keep). */
    private function keep(string $abs): ?string
    {
        if (!is_file($abs) || is_link($abs) || filesize($abs) > self::HIST_MAX_FILE) {
            return null;
        }
        $rel = $this->rel($abs);
        $dir = $this->historyDir() . '/' . sha1($rel);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        file_put_contents("$dir/path", $rel);
        $v = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
        copy($abs, "$dir/$v");
        $all = glob("$dir/2*") ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - self::HIST_KEEP)) as $old) {
            unlink($old);
        }
        return $v;
    }

    /** mkdir -p inside the root, refusing links and files in the way. */
    private function mkdirp(string $rel): void
    {
        $cur = '';
        foreach (explode('/', $this->clean($rel)) as $part) {
            if ($part === '') {
                continue;
            }
            $cur = $cur === '' ? $part : "$cur/$part";
            $abs = $this->path($cur, false, true);
            if (is_link($abs)) {
                throw new \RuntimeException("$cur is a link; refused");
            }
            if (!file_exists($abs)) {
                mkdir($abs, 0755);
            } elseif (!is_dir($abs)) {
                throw new \RuntimeException("$cur exists and is not a folder");
            }
        }
    }

    /** Atomic write (temporary file + rename), keeping the previous version. */
    private function put(string $rel, string $data): array
    {
        $rel = $this->clean($rel);
        if ($rel === '') {
            throw new \InvalidArgumentException('File path required');
        }
        $parent = dirname($rel);
        $this->mkdirp($parent === '.' ? '' : $parent);
        $abs = $this->path($rel, false);
        if (is_dir($abs)) {
            throw new \RuntimeException("$rel is a folder");
        }
        $existed = is_file($abs);
        $v = $existed ? $this->keep($abs) : null;
        $tmp = dirname($abs) . '/.' . basename($abs) . '.havoc-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $data) === false) {
            throw new \RuntimeException("Cannot write $rel");
        }
        chmod($tmp, $existed ? (fileperms($abs) & 0777) : 0644);
        rename($tmp, $abs);
        return ['path' => $rel, 'bytes' => strlen($data), 'created' => !$existed] + ($v ? ['previous_version' => $v] : []);
    }

    private static function decode(array $f): string
    {
        $c = (string)($f['content'] ?? '');
        if (($f['encoding'] ?? 'text') === 'base64') {
            $d = base64_decode($c, true);
            if ($d === false) {
                throw new \InvalidArgumentException('Invalid base64 for ' . ($f['path'] ?? ''));
            }
            return $d;
        }
        return $c;
    }

    /** Several files in one call; everything is checked before the first file is written. */
    private function putMany(array $files): array
    {
        if (!$files || count($files) > 200) {
            throw new \InvalidArgumentException('Give 1-200 files');
        }
        $data = [];
        $total = 0;
        foreach ($files as $f) {
            $rel = $this->clean((string)($f['path'] ?? ''));
            if ($rel === '') {
                throw new \InvalidArgumentException('Every file needs a path');
            }
            $data[$rel] = self::decode((array)$f);
            $total += strlen($data[$rel]);
        }
        if ($total > self::BATCH_BYTES) {
            throw new \RuntimeException('Too much data in one call (max 16 MB); split it or upload an archive');
        }
        $out = [];
        foreach ($data as $rel => $d) {
            $r = $this->put($rel, $d);
            $out[] = $rel . ($r['created'] ? ' (new)' : '');
        }
        return ['written' => $out, 'bytes' => $total];
    }

    /** Exact text replacements; each "old" must occur exactly once unless replace_all is set. */
    private function edit(string $rel, array $edits): array
    {
        $abs = $this->path($rel);
        if (!is_file($abs) || filesize($abs) > self::MAX_EDIT) {
            throw new \RuntimeException('Not a file or larger than 2 MB');
        }
        $c = (string)file_get_contents($abs);
        if (str_contains($c, "\0")) {
            throw new \RuntimeException('Binary file');
        }
        if (!$edits || count($edits) > 50) {
            throw new \InvalidArgumentException('Give 1-50 edits');
        }
        $lines = [];
        foreach (array_values($edits) as $i => $e) {
            $old = (string)($e['old'] ?? '');
            $new = (string)($e['new'] ?? '');
            if ($old === '') {
                throw new \InvalidArgumentException('Edit ' . ($i + 1) . ': "old" is empty');
            }
            $n = substr_count($c, $old);
            if ($n === 0) {
                throw new \RuntimeException('Edit ' . ($i + 1) . ': text not found (it must match exactly, including spaces and line breaks). Nothing was changed.');
            }
            if ($n > 1 && empty($e['replace_all'])) {
                throw new \RuntimeException('Edit ' . ($i + 1) . ": text found $n times; include more surrounding lines or set replace_all. Nothing was changed.");
            }
            $lines[] = substr_count(substr($c, 0, strpos($c, $old)), "\n") + 1;
            $c = empty($e['replace_all']) ? substr_replace($c, $new, strpos($c, $old), strlen($old)) : str_replace($old, $new, $c);
        }
        $r = $this->put($rel, $c);
        return ['path' => $r['path'], 'edits' => count($edits), 'at_lines' => $lines, 'bytes' => $r['bytes']] + (isset($r['previous_version']) ? ['previous_version' => $r['previous_version']] : []);
    }

    /** Lines from..to (1-based, inclusive) of a text file. */
    private function readRange(string $rel, int $from, int $to): array
    {
        $abs = $this->path($rel);
        if (!is_file($abs)) {
            throw new \RuntimeException('Not a file');
        }
        $fh = fopen($abs, 'rb');
        $from = max(1, $from);
        $to = $to > 0 ? $to : $from + self::READ_LINES - 1;
        $to = min($to, $from + self::READ_LINES - 1);
        $out = '';
        $n = 0;
        $cut = false;
        while (($l = fgets($fh)) !== false) {
            $n++;
            if ($n >= $from && $n <= $to) {
                if (str_contains($l, "\0")) {
                    fclose($fh);
                    throw new \RuntimeException('Binary file');
                }
                if (strlen($out) + strlen($l) > self::READ_BYTES) {
                    $cut = true;
                    $to = $n - 1;
                    break;
                }
                $out .= $l;
            }
        }
        if ($cut || !feof($fh)) {
            while (fgets($fh) !== false) {
                $n++;                                                   // count the rest for total_lines
            }
        }
        fclose($fh);
        return ['path' => $this->clean($rel), 'from' => $from, 'to' => min($to, $n), 'total_lines' => $n,
            'content' => mb_convert_encoding($out, 'UTF-8', 'UTF-8')];
    }

    /** Text search below a folder; skips binary and large files, .git, node_modules and the panel's own files. */
    private function grep(string $rel, string $q, bool $regex, bool $icase, string $glob, int $max): array
    {
        if ($q === '' || strlen($q) > 500) {
            throw new \InvalidArgumentException('Search text required (max 500 characters)');
        }
        $pattern = $regex ? '~' . str_replace('~', '\~', $q) . '~u' . ($icase ? 'i' : '') : null;
        if ($pattern !== null && @preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('Invalid regular expression');
        }
        $base = $this->path($rel);
        $max = max(1, min(300, $max));
        $hits = [];
        $files = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f) => !$f->isLink() && !in_array($f->getFilename(), ['.git', 'node_modules', '.havoc-history'], true)
        ));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getSize() > self::MAX_EDIT || ($glob !== '' && !fnmatch($glob, $f->getFilename()))
                || str_contains($f->getFilename(), '.havoc-')) {
                continue;
            }
            if (++$files > 50000) {
                break;
            }
            $fh = @fopen($f->getPathname(), 'rb');
            if (!$fh) {
                continue;
            }
            $head = (string)fread($fh, 8192);
            if (str_contains($head, "\0")) {
                fclose($fh);
                continue;
            }
            rewind($fh);
            $ln = 0;
            while (($l = fgets($fh)) !== false) {
                $ln++;
                $ok = $pattern !== null ? preg_match($pattern, $l) === 1 : ($icase ? stripos($l, $q) !== false : str_contains($l, $q));
                if ($ok) {
                    $hits[] = $this->rel($f->getPathname()) . ':' . $ln . ': ' . mb_strimwidth(trim(mb_convert_encoding($l, 'UTF-8', 'UTF-8')), 0, 240, '…');
                    if (count($hits) >= $max) {
                        fclose($fh);
                        return ['matches' => $hits, 'truncated' => true, 'files_searched' => $files];
                    }
                }
            }
            fclose($fh);
        }
        return ['matches' => $hits, 'truncated' => false, 'files_searched' => $files];
    }

    /** Versions of one file, or (no path) the files that have versions, newest first. */
    private function historyList(string $rel): array
    {
        $hd = $this->historyDir();
        if ($this->clean($rel) !== '') {
            $dir = $hd . '/' . sha1($this->clean($rel));
            $v = glob("$dir/2*") ?: [];
            rsort($v);
            return ['path' => $this->clean($rel), 'versions' => array_map(static fn($f) => ['version' => basename($f), 'bytes' => filesize($f)], $v)];
        }
        $out = [];
        foreach (glob("$hd/*/path") ?: [] as $p) {
            $v = glob(dirname($p) . '/2*') ?: [];
            if ($v) {
                rsort($v);
                $out[] = ['path' => (string)file_get_contents($p), 'versions' => count($v), 'latest' => basename($v[0])];
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['latest'], $a['latest']));
        return ['files' => array_slice($out, 0, 100)];
    }

    /** Puts a saved version back (the current content becomes a version itself, so restoring can be undone too). */
    private function historyRestore(string $rel, string $version): array
    {
        $rel = $this->clean($rel);
        if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/D', $version)) {
            throw new \InvalidArgumentException('Invalid version');
        }
        $src = $this->historyDir() . '/' . sha1($rel) . '/' . $version;
        if (!is_file($src)) {
            throw new \RuntimeException('Version not found (see list_file_history)');
        }
        $r = $this->put($rel, (string)file_get_contents($src));
        return ['path' => $rel, 'restored' => $version] + (isset($r['previous_version']) ? ['previous_version' => $r['previous_version']] : []);
    }

    /** Entries of a zip / tar archive after safety checks: [name => size] for files. */
    private function archiveEntries(string $abs): array
    {
        $files = [];
        if (preg_match('/\.zip$/i', $abs)) {
            $z = new \ZipArchive();
            if ($z->open($abs) !== true) {
                throw new \RuntimeException('Not a readable zip file');
            }
            for ($i = 0; $i < $z->numFiles; $i++) {
                $s = $z->statIndex($i);
                $name = (string)$s['name'];
                $z->getExternalAttributesIndex($i, $os, $attr);
                if ($os === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException("Archive contains a symbolic link ($name); refused");
                }
                if (str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new \RuntimeException('Unsafe path in archive');
                }
                if (!str_ends_with($name, '/')) {
                    $files[$name] = (int)$s['size'];
                }
            }
            $z->close();
        } elseif (preg_match('/\.(tar\.gz|tgz|tar)$/i', $abs)) {
            $p = proc_open(['tar', '-tvf', $abs], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            while (($line = fgets($pipes[1])) !== false) {
                if (!in_array($line[0], ['-', 'd'], true)) {
                    throw new \RuntimeException('Archive contains links or special files; refused');
                }
                if (!preg_match('/^\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.*)$/D', rtrim($line, "\n"), $m)
                    || str_starts_with($m[2], '/') || preg_match('#(^|/)\.\.(/|$)#', $m[2])) {
                    throw new \RuntimeException('Unsafe or unreadable entry in archive');
                }
                if ($line[0] === '-') {
                    $files[preg_replace('#^\./#', '', $m[2])] = (int)$m[1];
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($p) !== 0) {
                throw new \RuntimeException('Not a readable tar archive');
            }
        } else {
            throw new \InvalidArgumentException('Supported archives: .zip, .tar.gz, .tgz, .tar');
        }
        if (count($files) > 100000 || array_sum($files) > 4 * 1024 ** 3) {
            throw new \RuntimeException('Archive too large');
        }
        return $files;
    }

    /**
     * Extracts an archive that is inside the site. Existing files are only replaced with overwrite=true, and then
     * their previous content goes into the history. strip_top_folder drops a single top folder (e.g. "mysite/").
     */
    private function extract(string $rel, string $dest, bool $overwrite, bool $strip, bool $cleanup = false): array
    {
        $abs = $this->path($rel);
        $files = $this->archiveEntries($abs);
        if (!$files) {
            throw new \RuntimeException('The archive contains no files');
        }
        $prefix = '';
        if ($strip) {
            $tops = array_unique(array_map(static fn($n) => explode('/', $n, 2)[0], array_keys($files)));
            if (count($tops) === 1 && !isset($files[$tops[0]])) {
                $prefix = $tops[0] . '/';
            }
        }
        $dest = $this->clean($dest);
        $this->mkdirp($dest);
        $to = $this->path($dest, false);
        $conflicts = [];
        foreach (array_keys($files) as $n) {
            $target = $to . '/' . substr($n, strlen($prefix));
            if (file_exists($target) || is_link($target)) {
                $conflicts[] = substr($n, strlen($prefix));
            }
        }
        if ($conflicts && !$overwrite) {
            throw new \RuntimeException(count($conflicts) . ' file(s) already exist, e.g. ' . implode(', ', array_slice($conflicts, 0, 8))
                . '. Nothing was extracted. Set overwrite to true to replace them (previous versions are kept).');
        }
        // extract into a hidden staging folder next to the target, check it, then move files into place
        $stage = $to . '/.havoc-extract-' . bin2hex(random_bytes(4));
        mkdir($stage, 0700);
        try {
            if (preg_match('/\.zip$/i', $abs)) {
                $z = new \ZipArchive();
                $z->open($abs);
                $z->extractTo($stage);
                $z->close();
            } else {
                $this->tar(['-xf', $abs, '-C', $stage, '--no-same-owner', '--no-same-permissions']);
            }
            $moved = 0;
            $kept = 0;
            foreach (array_keys($files) as $n) {
                $src = $stage . '/' . $n;
                if (!is_file($src) || is_link($src)) {
                    continue;
                }
                $relTarget = ltrim(($dest === '' ? '' : $dest . '/') . substr($n, strlen($prefix)), '/');
                $parent = dirname($relTarget);
                $this->mkdirp($parent === '.' ? '' : $parent);
                $target = $this->path($relTarget, false);
                if (is_dir($target)) {
                    continue;
                }
                if (is_file($target) && $this->keep($target)) {
                    $kept++;
                }
                chmod($src, 0644);
                rename($src, $target);
                $moved++;
            }
        } finally {
            $this->rmTree($stage);
        }
        // archives that came in through an upload link are removed once they are extracted (nothing else is ever deleted)
        $removed = false;
        if ($cleanup && str_starts_with($this->clean($rel), self::INCOMING . '/') && is_file($abs) && !is_link($abs)) {
            $removed = unlink($abs);
        }
        return ['extracted_files' => $moved, 'replaced' => count($conflicts), 'versions_kept' => $kept, 'into' => $dest === '' ? '/' : $dest]
            + ($removed ? ['archive_removed' => true] : []);
    }

    /** Removes uploads older than $days from the incoming folder (only there). */
    private function incomingClean(int $days): array
    {
        $dir = $this->root . '/' . self::INCOMING;
        $n = 0;
        if (is_dir($dir) && !is_link($dir)) {
            foreach (scandir($dir) ?: [] as $f) {
                $p = "$dir/$f";
                if (is_file($p) && !is_link($p) && filemtime($p) < time() - max(1, $days) * 86400) {
                    unlink($p) && $n++;
                }
            }
        }
        return ['removed' => $n];
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Downloads an https:// URL into the site. Every address the host resolves to must be public (no access to the
     * server's own or internal services); the connection is pinned to the checked address, and each redirect is
     * checked the same way.
     */
    private function fetch(string $url, string $rel, bool $overwrite): array
    {
        $rel = $this->clean($rel);
        if ($rel === '') {
            throw new \InvalidArgumentException('Target path required');
        }
        $parent = dirname($rel);
        $this->mkdirp($parent === '.' ? '' : $parent);
        $abs = $this->path($rel, false);
        if (is_dir($abs)) {
            throw new \RuntimeException("$rel is a folder");
        }
        if (file_exists($abs) && !$overwrite) {
            throw new \RuntimeException("$rel already exists; set overwrite to true to replace it (the previous version is kept)");
        }
        $tmp = dirname($abs) . '/.' . basename($abs) . '.havoc-dl-' . bin2hex(random_bytes(4));
        try {
            for ($hop = 0; $hop <= 3; $hop++) {
                $u = parse_url($url);
                if (($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || (isset($u['port']) && $u['port'] !== 443)) {
                    throw new \InvalidArgumentException('Only https:// addresses on port 443 are allowed');
                }
                $host = strtolower($u['host']);
                $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
                if (!$ips) {
                    throw new \RuntimeException("Cannot resolve $host");
                }
                foreach ($ips as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        throw new \RuntimeException("$host points to a private address; refused");
                    }
                }
                $hdr = $tmp . '.h';
                $p = proc_open(['curl', '-sS', '--proto', '=https', '--max-redirs', '0', '--resolve', "$host:443:{$ips[0]}", '--max-filesize', (string)self::FETCH_MAX,
                    '--connect-timeout', '20', '--max-time', '900', '-D', $hdr, '-o', $tmp, '-w', '%{http_code}', $url],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $code = (int)stream_get_contents($pipes[1]);
                $err = trim((string)stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $rc = proc_close($p);
                $headers = (string)@file_get_contents($hdr);
                @unlink($hdr);
                if ($rc !== 0) {
                    throw new \RuntimeException('Download failed: ' . ($err ?: "curl exit $rc"));
                }
                if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/mi', $headers, $m)) {
                    $next = trim($m[1]);
                    $url = str_starts_with($next, 'https://') ? $next : ($next[0] === '/' ? "https://$host$next" : '');
                    if ($url === '') {
                        throw new \RuntimeException('Unsupported redirect');
                    }
                    continue;
                }
                if ($code !== 200) {
                    throw new \RuntimeException("The server answered HTTP $code");
                }
                $existed = is_file($abs);
                $v = $existed ? $this->keep($abs) : null;
                chmod($tmp, 0644);
                rename($tmp, $abs);
                return ['path' => $rel, 'bytes' => filesize($abs), 'sha256' => hash_file('sha256', $abs)] + ($v ? ['previous_version' => $v] : []);
            }
            throw new \RuntimeException('Too many redirects');
        } finally {
            @unlink($tmp);
        }
    }
}
