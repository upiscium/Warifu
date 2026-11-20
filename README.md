# Warifu for Nextcloud v0.3.0

Warifu（割符）は Nextcloud 標準 Files WebDAV にファイル内容由来の SHA-256 ETag と条件付き更新を加えるアプリです。アプリIDは `nextcloud_gateway`。

v0.3.0 は未署名アーカイブによる手動導入です。Nextcloud 35.0.3、PHP 8.5.12、通常の非暗号化ローカルホームを対象に限定します。署名検証やアプリストア自動更新を期待しないでください。

| 確認内容 | 手順 |
| --- | --- |
| 新規導入 | [導入手順](docs/install-nextcloud.md) |
| v0.2.0 から更新・復旧 | [更新とロールバック](docs/upgrade.md) |
| サーバー設定・DAV応答の確認 | [サーバー確認](docs/check-server.md) |
| 変更点・アーカイブ照合 | [GAノート](docs/ga-notes.md) |

新規導入では `scope_mode=all` が既定で、全ユーザーのローカルホームを対象にします。既存 v0.2.0 からの更新では `experimental_enabled=yes` の場合のみ従来の選択登録相当となり、それ以外・未設定は `off` です。更新で全体へ自動拡張しません。管理者が設定画面で明示的に選択してください。`allowed_roots` は内容条件の適用範囲であり、Nextcloudのアクセス制御（ACL）ではありません。

外部ストレージ、暗号化、サブマウントを含む構成は対象外です。SHA ETag計算はファイル全体を読むため、1ファイルのサイズ上限より先に累積ハッシュ予算へ達することがあります。

以前の版は [v0.2.0 の手順](https://github.com/upiscium/Warifu/tree/v0.2.0/docs) を参照してください。
