# Warifu for Nextcloud v0.3.1

Warifu（割符）は Nextcloud 標準 Files WebDAV にファイル内容由来の SHA-256 ETag と条件付き更新を加えるアプリです。アプリIDは `nextcloud_gateway`。

v0.3.1 は未署名アーカイブによる手動導入です。対象は Nextcloud 35.0.3、64-bit PHP 8.5.12、通常の非暗号化ローカルホームです。署名検証やアプリストア自動更新を期待しないでください。

| 内容 | 手順 |
| --- | --- |
| 新規導入 | [導入手順](docs/install-nextcloud.md) |
| v0.3.0 / v0.3.1-rc.1 から更新・復旧 | [更新手順](docs/upgrade.md) |
| サーバー設定・DAV応答の確認 | [サーバーの `occ` と手元のPCの `curl`](docs/check-server.md) |
| 変更点・確認範囲 | [GAノート](docs/ga-notes.md) |

新規導入では `scope_mode=all` が既定で全ユーザーの通常ローカルホームが対象です。既存版からの更新では従来のscope動作を引き継ぎ、全体へ自動拡張しません。`allowed_roots` は内容条件の適用範囲であり、Nextcloudのアクセス制御（ACL）ではありません。

**管理者設定 → 基本設定 → Warifu** で、1ファイルの上限（既定10 GiB）と1要求の累積ハッシュ量（既定50 GiB）を独立して設定できます。入力はそれぞれ1〜4294967295 GiBの整数です。実際のDAV処理では `config.php` とユーザーごとのより低い上限も適用されます。外部ストレージ、暗号化、サブマウントは対象外です。
