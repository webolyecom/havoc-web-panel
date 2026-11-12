<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * File operations confined to one document root. Runs INSIDE `fsworker`, which havocctl starts as the
 * tenant's own Linux user (setpriv), so even a path bug can never touch more than the tenant could already touch.
 */
final class Fs
{
    private const MAX_EDIT = 2 * 1024 * 1024;
    private const MAX_B64  = 24000000;
    private string $root;

    public function __construct(string $root)
    {
        $r = realpath($root);
        if ($r === false || !is_dir($r)) {
            throw new \RuntimeException('Document root not found');
        }
        $this->root = $r;
    }

    private function inside(string $p): bool
    {
        return $p === $this->root || str_starts_with($p, $this->root . '/');
    }

    private function clean(string $rel): string
    {
        $parts = [];
        foreach (explode('/', str_replace("\0", '', $rel)) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                throw new \InvalidArgumentException('Invalid path');
            }
            $parts[] = $p;
        }
        return implode('/', $parts);
    }

    /** Resolve a relative path to an absolute one that is guaranteed to stay inside the root. */
    private function path(string $rel, bool $mustExist = true, bool $asLink = false): string
    {
        $rel  = $this->clean($rel);
        $full = $rel === '' ? $this->root : $this->root . '/' . $rel;
        $exists = file_exists($full) || is_link($full);
        if ($mustExist && !$exists) {
            throw new \RuntimeException('Not found');
        }
        $parent = realpath($rel === '' ? $this->root : dirname($full));
        if ($parent === false || !$this->inside($parent)) {
            throw new \InvalidArgumentException('Path outside document root');
        }
        if ($exists && (!is_link($full) || !$asLink)) {
            $rp = realpath($full);
            if ($rp === false || !$this->inside($rp)) {
                throw new \InvalidArgumentException('Path outside document root');
            }
        }
        return $full;
    }

    private function rel(string $abs): string
    {
        return ltrim(substr($abs, strlen($this->root)), '/');
    }

    public function run(string $op, array $a): array
    {
        return match ($op) {
            'list'     => $this->list((string)($a['path'] ?? '')),
            'read'     => $this->read((string)($a['path'] ?? '')),
            'write'    => $this->write((string)($a['path'] ?? ''), (string)($a['content'] ?? '')),
            'mkdir'    => $this->mkdir((string)($a['path'] ?? '')),
            'touch'    => $this->touch((string)($a['path'] ?? '')),
            'delete'   => $this->delete((array)($a['paths'] ?? [])),
            'rename'   => $this->rename((string)($a['from'] ?? ''), (string)($a['to'] ?? '')),
            'chmod'    => $this->chmod((string)($a['path'] ?? ''), (string)($a['mode'] ?? '')),
            'zip'      => $this->zip((array)($a['paths'] ?? []), (string)($a['dest'] ?? '')),
            'unzip'    => $this->unzip((string)($a['path'] ?? ''), (string)($a['dest'] ?? '')),
            'upload'   => $this->upload((string)($a['path'] ?? ''), (string)($a['b64'] ?? '')),
            'download' => $this->download((string)($a['path'] ?? '')),
            'copy'     => $this->copy((array)($a['paths'] ?? []), (string)($a['dest'] ?? '')),
            'move'     => $this->move((array)($a['paths'] ?? []), (string)($a['dest'] ?? '')),
            'search'   => $this->search((string)($a['path'] ?? ''), (string)($a['q'] ?? '')),
            'chmodr'   => $this->chmodMany((array)($a['paths'] ?? []), (string)($a['file_mode'] ?? ''), (string)($a['dir_mode'] ?? ''), !empty($a['recursive'])),
            'targz'    => $this->targz((array)($a['paths'] ?? []), (string)($a['dest'] ?? '')),
            'untar'    => $this->untar((string)($a['path'] ?? ''), (string)($a['dest'] ?? '')),
            'chunk'    => $this->chunk((string)($a['path'] ?? ''), (string)($a['b64'] ?? ''), (int)($a['offset'] ?? -1), !empty($a['final'])),
            'preview'  => $this->preview((string)($a['path'] ?? '')),
            default    => throw new \InvalidArgumentException('Unknown operation'),
        };
    }

    private function list(string $rel): array
    {
        $dir = $this->path($rel);
        if (!is_dir($dir)) {
            throw new \RuntimeException('Not a directory');
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $f = "$dir/$n";
            $out[] = ['name' => $n, 'dir' => is_dir($f) && !is_link($f), 'link' => is_link($f),
                'size' => is_file($f) ? (int)filesize($f) : 0, 'mtime' => (int)filemtime($f),
                'perm' => sprintf('%03o', fileperms($f) & 0777)];
        }
        usort($out, static fn($x, $y) => [$y['dir'], strtolower($x['name'])] <=> [$x['dir'], strtolower($y['name'])]);
        return ['path' => $this->rel($dir), 'items' => $out];
    }

    private function read(string $rel): array
    {
        $f = $this->path($rel);
        if (!is_file($f) || filesize($f) > self::MAX_EDIT) {
            throw new \RuntimeException('File too large to edit (max 2 MB)');
        }
        $c = (string)file_get_contents($f);
        if (str_contains($c, "\0")) {
            throw new \RuntimeException('Binary file');
        }
        return ['content' => mb_convert_encoding($c, 'UTF-8', 'UTF-8')];
    }

    private function write(string $rel, string $content): array
    {
        if (strlen($content) > self::MAX_EDIT) {
            throw new \RuntimeException('Content too large');
        }
        $f = $this->path($rel, false);
        if (is_dir($f)) {
            throw new \RuntimeException('Is a directory');
        }
        file_put_contents($f, $content, LOCK_EX);
        return [];
    }

    private function mkdir(string $rel): array
    {
        $f = $this->path($rel, false);
        if (file_exists($f)) {
            throw new \RuntimeException('Already exists');
        }
        mkdir($f, 0755);
        return [];
    }

    private function touch(string $rel): array
    {
        $f = $this->path($rel, false);
        if (file_exists($f)) {
            throw new \RuntimeException('Already exists');
        }
        touch($f);
        chmod($f, 0644);
        return [];
    }

    private function rrm(string $p): void
    {
        if (is_link($p) || !is_dir($p)) {
            unlink($p);
            return;
        }
        foreach (scandir($p) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') {
                $this->rrm("$p/$n");
            }
        }
        rmdir($p);
    }

    private function delete(array $paths): array
    {
        foreach ($paths as $rel) {
            if ($this->clean((string)$rel) === '') {
                throw new \InvalidArgumentException('Cannot delete the document root');
            }
            $this->rrm($this->path((string)$rel, true, true));   // links are removed, never followed
        }
        return [];
    }

    private function rename(string $from, string $to): array
    {
        $src = $this->path($from, true, true);
        $dst = $this->path($to, false);
        if ($this->clean($from) === '' || file_exists($dst)) {
            throw new \RuntimeException('Invalid source or target exists');
        }
        rename($src, $dst);
        return [];
    }

    private function chmod(string $rel, string $mode): array
    {
        if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
            throw new \InvalidArgumentException('Invalid mode');
        }
        chmod($this->path($rel), octdec($mode) & 0777);       // no setuid/setgid/sticky
        return [];
    }

    private function zip(array $paths, string $dest): array
    {
        $dst = $this->path(str_ends_with($dest, '.zip') ? $dest : $dest . '.zip', false);
        $z = new \ZipArchive();
        if ($z->open($dst, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create archive');
        }
        $n = 0;
        foreach ($paths as $rel) {
            $abs = $this->path((string)$rel);
            if (is_link($abs)) {
                continue;
            }
            if (is_file($abs)) {
                $z->addFile($abs, $this->rel($abs));
                $n++;
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isLink() || !$f->isFile()) {
                    continue;
                }
                if (++$n > 50000) {
                    throw new \RuntimeException('Too many files');
                }
                $z->addFile($f->getPathname(), $this->rel($f->getPathname()));
            }
        }
        $z->close();
        return [];
    }

    private function unzip(string $rel, string $dest): array
    {
        $f = $this->path($rel);
        $to = $this->path($dest === '' ? dirname($rel) : $dest, false);
        $z = new \ZipArchive();
        if ($z->open($f) !== true) {
            throw new \RuntimeException('Invalid archive');
        }
        $total = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $s = $z->statIndex($i);
            $name = (string)$s['name'];
            if (str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new \RuntimeException('Unsafe path in archive');       // zip-slip guard
            }
            $total += (int)$s['size'];
        }
        if ($total > 2 * 1024 ** 3 || $z->numFiles > 100000) {
            throw new \RuntimeException('Archive too large');
        }
        is_dir($to) || mkdir($to, 0755, true);
        $z->extractTo($to);
        $z->close();
        return [];
    }

    private function upload(string $rel, string $b64): array
    {
        if (strlen($b64) > self::MAX_B64) {
            throw new \RuntimeException('File too large (max 16 MB)');
        }
        $f = $this->path($rel, false);
        if (is_dir($f)) {
            throw new \RuntimeException('Is a directory');
        }
        $data = base64_decode($b64, true);
        if ($data === false) {
            throw new \InvalidArgumentException('Bad upload');
        }
        file_put_contents($f, $data, LOCK_EX);
        chmod($f, 0644);
        return [];
    }

    private function download(string $rel): array
    {
        $f = $this->path($rel);
        if (!is_file($f) || filesize($f) > 16 * 1024 * 1024) {
            throw new \RuntimeException('Not a file or larger than 16 MB');
        }
        return ['name' => basename($f), 'b64' => base64_encode((string)file_get_contents($f))];
    }

    // ---- extended operations ---------------------------------------------------------------------------------

    private const MAX_UPLOAD = 2 * 1024 ** 3;
    private const IMAGES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
                            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'avif' => 'image/avif', 'bmp' => 'image/bmp'];

    private function destDir(string $dest): string
    {
        $d = $this->path($dest);
        if (!is_dir($d) || is_link($d)) {
            throw new \RuntimeException('Target folder does not exist');
        }
        return $d;
    }

    /** Copies files and folders recursively; symbolic links are copied as links, never followed. */
    private function copyTree(string $src, string $dst, int &$n): void
    {
        if (++$n > 20000) {
            throw new \RuntimeException('Too many files (max 20000)');
        }
        if (is_link($src)) {
            symlink((string)readlink($src), $dst);
        } elseif (is_dir($src)) {
            mkdir($dst, fileperms($src) & 0777);
            foreach (scandir($src) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    $this->copyTree("$src/$e", "$dst/$e", $n);
                }
            }
        } else {
            if (!copy($src, $dst)) {
                throw new \RuntimeException('Copy failed: ' . basename($src));
            }
            chmod($dst, fileperms($src) & 0777);
        }
    }

    private function freeName(string $dir, string $name): string
    {
        if (!file_exists("$dir/$name") && !is_link("$dir/$name")) {
            return "$dir/$name";
        }
        $dot = strpos($name, '.', 1);
        [$base, $ext] = $dot === false ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];
        for ($i = 1; $i < 1000; $i++) {
            $c = "$dir/$base (copy" . ($i > 1 ? " $i" : '') . ")$ext";
            if (!file_exists($c) && !is_link($c)) {
                return $c;
            }
        }
        throw new \RuntimeException('No free file name');
    }

    private function copy(array $paths, string $dest): array
    {
        $dir = $this->destDir($dest);
        $n = 0;
        foreach (array_slice($paths, 0, 500) as $rel) {
            $src = $this->path((string)$rel, true, true);
            if ($this->clean((string)$rel) === '') {
                throw new \InvalidArgumentException('Cannot copy the document root');
            }
            if (is_dir($src) && !is_link($src) && ($dir === $src || str_starts_with($dir . '/', $src . '/'))) {
                throw new \RuntimeException('Cannot copy a folder into itself');
            }
            $this->copyTree($src, $this->freeName($dir, basename($src)), $n);
        }
        return ['count' => $n];
    }

    private function move(array $paths, string $dest): array
    {
        $dir = $this->destDir($dest);
        foreach (array_slice($paths, 0, 500) as $rel) {
            if ($this->clean((string)$rel) === '') {
                throw new \InvalidArgumentException('Cannot move the document root');
            }
            $src = $this->path((string)$rel, true, true);
            if (is_dir($src) && !is_link($src) && ($dir === $src || str_starts_with($dir . '/', $src . '/'))) {
                throw new \RuntimeException('Cannot move a folder into itself');
            }
            $dst = $dir . '/' . basename($src);
            if (dirname($src) === $dir) {
                continue;
            }
            if (file_exists($dst) || is_link($dst)) {
                throw new \RuntimeException('Already exists in the target folder: ' . basename($src));
            }
            rename($src, $dst);
        }
        return [];
    }

    /** File name search below a folder (case-insensitive substring, symlinks not followed). */
    private function search(string $rel, string $q): array
    {
        $q = mb_strtolower(trim($q));
        if (mb_strlen($q) < 2) {
            throw new \InvalidArgumentException('Search for at least 2 characters');
        }
        $base = $this->path($rel);
        $out = [];
        $seen = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if (++$seen > 100000) {
                break;
            }
            if (str_contains(mb_strtolower($f->getFilename()), $q)) {
                $out[] = ['path' => $this->rel($f->getPathname()), 'dir' => $f->isDir() && !$f->isLink(), 'size' => $f->isFile() ? $f->getSize() : 0, 'mtime' => $f->getMTime()];
                if (count($out) >= 300) {
                    break;
                }
            }
        }
        return ['results' => $out, 'limited' => count($out) >= 300 || $seen > 100000];
    }

    private function mode(string $m): ?int
    {
        if ($m === '') {
            return null;
        }
        if (!preg_match('/^0?[0-7]{3}$/', $m)) {
            throw new \InvalidArgumentException('Invalid mode');
        }
        return octdec($m) & 0777;                              // never setuid/setgid/sticky
    }

    /** chmod for several entries; optionally recursive with separate modes for files and folders. Links are skipped. */
    private function chmodMany(array $paths, string $fileMode, string $dirMode, bool $recursive): array
    {
        $fm = $this->mode($fileMode);
        $dm = $this->mode($dirMode);
        $n = 0;
        $apply = function (string $p) use ($fm, $dm, &$n): void {
            if (is_link($p)) {
                return;
            }
            $m = is_dir($p) ? $dm : $fm;
            if ($m !== null) {
                chmod($p, $m);
                $n++;
            }
        };
        foreach (array_slice($paths, 0, 500) as $rel) {
            $abs = $this->path((string)$rel, true, true);
            $apply($abs);
            if ($recursive && is_dir($abs) && !is_link($abs)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $f) {
                    $apply($f->getPathname());
                }
            }
        }
        return ['count' => $n];
    }

    private function tar(array $args): void
    {
        $p = proc_open(['tar', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($code > 1) {                                          // 1 = "file changed while reading": acceptable
            throw new \RuntimeException('tar: ' . trim(mb_substr($err, 0, 300)));
        }
    }

    private function targz(array $paths, string $dest): array
    {
        if (!preg_match('/\.(tar\.gz|tgz)$/', $dest)) {
            $dest .= '.tar.gz';
        }
        $dst = $this->path($dest, false);
        if (file_exists($dst)) {
            throw new \RuntimeException('Already exists');
        }
        $rels = [];
        foreach (array_slice($paths, 0, 500) as $rel) {
            $this->path((string)$rel, true, true);
            $rels[] = $this->clean((string)$rel);
        }
        if (!$rels || in_array('', $rels, true)) {
            throw new \InvalidArgumentException('Select files inside the document root');
        }
        $this->tar(['-czf', $dst, '--exclude=' . basename($dst), '--', ...$rels]);
        return [];
    }

    /** Extracts .tar/.tar.gz/.tgz/.tar.bz2/.tar.xz. Archives with links, devices or unsafe paths are refused. */
    private function untar(string $rel, string $dest): array
    {
        $f = $this->path($rel);
        $to = $this->path($dest === '' ? dirname($this->clean($rel)) : $dest, false);
        $p = proc_open(['tar', '-tvf', $f], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $n = 0;
        $total = 0;
        while (($line = fgets($pipes[1])) !== false) {
            if (++$n > 100000) {
                throw new \RuntimeException('Archive has too many entries');
            }
            $type = $line[0];
            if (!in_array($type, ['-', 'd'], true)) {
                throw new \RuntimeException('Archive contains links or special files; refused');
            }
            if (!preg_match('/^\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.*)$/', rtrim($line, "\n"), $m)) {
                throw new \RuntimeException('Cannot read archive listing');
            }
            $total += (int)$m[1];
            $name = $m[2];
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new \RuntimeException('Unsafe path in archive');
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0 || $n === 0) {
            throw new \RuntimeException('Not a readable tar archive');
        }
        if ($total > 4 * 1024 ** 3) {
            throw new \RuntimeException('Archive too large');
        }
        is_dir($to) || mkdir($to, 0755, true);
        $this->tar(['-xf', $f, '-C', $to, '--no-same-owner', '--no-same-permissions', '--no-overwrite-dir']);
        return [];
    }

    /** Chunked upload: offset 0 starts a hidden part file, every chunk must continue exactly where the last one ended. */
    private function chunk(string $rel, string $b64, int $offset, bool $final): array
    {
        if (strlen($b64) > 12 * 1024 * 1024) {
            throw new \RuntimeException('Chunk too large');
        }
        $f = $this->path($rel, false);
        if (is_dir($f)) {
            throw new \RuntimeException('Is a directory');
        }
        $part = dirname($f) . '/.' . basename($f) . '.havoc-part';
        if (is_link($part)) {
            unlink($part);
        }
        $data = base64_decode($b64, true);
        if ($data === false) {
            throw new \InvalidArgumentException('Bad upload chunk');
        }
        if ($offset === 0) {
            file_put_contents($part, '');
        }
        clearstatcache(true, $part);
        if (!is_file($part) || filesize($part) !== $offset) {
            throw new \RuntimeException('Upload out of sync; start again');
        }
        if ($offset + strlen($data) > self::MAX_UPLOAD) {
            unlink($part);
            throw new \RuntimeException('File too large (max 2 GB)');
        }
        file_put_contents($part, $data, FILE_APPEND | LOCK_EX);
        if ($final) {
            chmod($part, 0644);
            rename($part, $f);
        }
        return ['size' => $offset + strlen($data)];
    }

    private function preview(string $rel): array
    {
        $f = $this->path($rel);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!isset(self::IMAGES[$ext]) || !is_file($f) || filesize($f) > 5 * 1024 * 1024) {
            throw new \RuntimeException('No preview for this file');
        }
        return ['mime' => self::IMAGES[$ext], 'b64' => base64_encode((string)file_get_contents($f)), 'size' => filesize($f)];
    }

    /** For streaming downloads: absolute path of a regular file inside the root. */
    public function fileForStream(string $rel): string
    {
        $f = $this->path($rel);
        if (!is_file($f) || is_link($f)) {
            throw new \RuntimeException('Not a file');
        }
        return $f;
    }
}
