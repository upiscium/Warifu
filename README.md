# Warifu for Nextcloud v0.2.0

Warifu（割符）は、Nextcloud 標準 Files WebDAV にファイル内容由来の SHA-256 ETag と条件付き更新を加えるアプリです。アプリIDは `nextcloud_gateway` です。

v0.2.0 は**未署名の手動導入版**です。署名検証やNextcloudアプリストア経由の自動更新を前提にしないでください。導入・更新前にアーカイブのSHA-256を、配布物と独立して入手したチェックサムと照合してください。

| 確認したいこと | 手順 |
| --- | --- |
| 稼働中の版・登録先・SHA ETag | [サーバーで `occ`、クライアントで `curl`](docs/check-server.md) |
| 新規導入 | [アーカイブを照合して専用フォルダで試す](docs/install-nextcloud.md) |
| RC1またはv0.1.0から更新・切戻し | [バックアップ、`occ upgrade`、復元](docs/upgrade.md) |
| v0.2.0の変更点・配布物のSHA-256 | [GAノート](docs/ga-notes.md) |

対象はNextcloud **35.0.3** の固定公式Apacheイメージ（PHP **8.5.12**）、非暗号化のローカルホーム、DBロックまたは対応Redis構成です。アプリの実装は `v0.2.0-rc.1` と同じです。
