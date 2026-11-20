# Warifu for Nextcloud v0.3.1-rc.1

Warifu（割符）は Nextcloud 標準 Files WebDAV にファイル内容由来の SHA-256 ETag と条件付き更新を加えるアプリです。アプリIDは `nextcloud_gateway`。

v0.3.1-rc.1 は未署名アーカイブによる手動導入のリリース候補です。対象は Nextcloud 35.0.3、64-bit PHP 8.5.12、通常の非暗号化ローカルホームです。署名検証やアプリストア自動更新を期待しないでください。

| 内容 | 手順 |
| --- | --- |
| 新規導入 | [導入手順](docs/install-nextcloud.md) |
| v0.3.0 から更新・復旧 | [更新手順](docs/upgrade.md) |
| サーバー設定・DAV応答の確認 | [サーバー確認](docs/check-server.md) |
| RCの変更点・確認範囲 | [RCノート](docs/rc-notes.md) |

新規導入では `scope_mode=all` が既定で全ユーザーの通常ローカルホームが対象です。v0.3.0からの更新では従来のscope動作を引き継ぎ、全体へ自動拡張しません。`allowed_roots` は内容条件の適用範囲であり、Nextcloudのアクセス制御（ACL）ではありません。

アプリ既定の1ファイル上限は10 GiB、要求単位の累積ハッシュ予算は50 GiBです。管理者は1〜4294967295 GiBの整数をそれぞれ独立して設定できます。これらは `config.php` の上限やユーザーのバイト上限を引き上げません。外部ストレージ、暗号化、サブマウントは対象外です。
