# Installation

The maintained installation, upgrade, backup, and administrator-initialization
instructions are in [README.md](README.md#linux-server-deployment).

Important layout rule: the repository root is the plugin source root, but the
installed DokuWiki directory must be named:

```text
<DOKUWIKI_ROOT>/lib/plugins/instrumentbooking/
```

After copying the files, initialize the SQLite schema with `bin/install.php`
and initialize the first independent TRSys administrator with
`bin/admin.php bootstrap`. Both steps are required and are documented in the
README.
