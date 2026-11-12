// Havoc code editor bundle: CodeMirror 6 (MIT) with language modes picked by file extension.
import { EditorView, basicSetup } from 'codemirror';
import { EditorState, Compartment } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { StreamLanguage } from '@codemirror/language';
import { php } from '@codemirror/lang-php';
import { javascript } from '@codemirror/lang-javascript';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { json } from '@codemirror/lang-json';
import { sql } from '@codemirror/lang-sql';
import { xml } from '@codemirror/lang-xml';
import { markdown } from '@codemirror/lang-markdown';
import { oneDark } from '@codemirror/theme-one-dark';
import { nginx } from '@codemirror/legacy-modes/mode/nginx';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { yaml } from '@codemirror/legacy-modes/mode/yaml';
import { properties } from '@codemirror/legacy-modes/mode/properties';
import { python } from '@codemirror/legacy-modes/mode/python';

function language(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  const base = name.split('/').pop().toLowerCase();
  if (base === '.htaccess' || base.endsWith('.conf') || base === 'nginx.conf') return StreamLanguage.define(nginx);
  if (base === '.env' || base.startsWith('.env.')) return StreamLanguage.define(properties);
  switch (ext) {
    case 'php': case 'phtml': return php();
    case 'js': case 'mjs': case 'cjs': return javascript();
    case 'ts': return javascript({ typescript: true });
    case 'jsx': return javascript({ jsx: true });
    case 'tsx': return javascript({ jsx: true, typescript: true });
    case 'css': case 'scss': case 'less': return css();
    case 'html': case 'htm': case 'twig': case 'blade': case 'tpl': return html();
    case 'json': case 'webmanifest': return json();
    case 'sql': return sql();
    case 'xml': case 'svg': return xml();
    case 'md': case 'markdown': return markdown();
    case 'sh': case 'bash': return StreamLanguage.define(shell);
    case 'yml': case 'yaml': return StreamLanguage.define(yaml);
    case 'ini': case 'env': case 'properties': case 'cnf': return StreamLanguage.define(properties);
    case 'py': return StreamLanguage.define(python);
    default: return [];
  }
}

window.HavocEditor = {
  create(parent, opts) {
    const theme = new Compartment();
    const view = new EditorView({
      parent,
      state: EditorState.create({
        doc: opts.value || '',
        extensions: [
          basicSetup,
          keymap.of([indentWithTab, { key: 'Mod-s', preventDefault: true, run: () => { opts.onSave && opts.onSave(); return true; } }]),
          language(opts.filename || ''),
          theme.of(opts.dark ? oneDark : []),
          EditorView.cspNonce.of(opts.nonce || ''),
          EditorView.theme({ '&': { height: '100%', fontSize: '13px' }, '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' } }),
          EditorView.updateListener.of((u) => { if (u.docChanged && opts.onChange) opts.onChange(); }),
        ],
      }),
    });
    return {
      getValue: () => view.state.doc.toString(),
      focus: () => view.focus(),
      setDark: (d) => view.dispatch({ effects: theme.reconfigure(d ? oneDark : []) }),
      destroy: () => view.destroy(),
    };
  },
};
