# Warifu for Nextcloud 0.1.0

Warifu（割符）は、Nextcloud 標準 Files WebDAV に内容 SHA-256 の ETag と条件付き更新を追加するサーバーアプリです。アプリ ID は `nextcloud_gateway` です。無条件 PUT を禁止する機能ではありません。

**0.1.0 は未署名の手動導入版です。** [インストールガイド](docs/install-nextcloud.md)に従い、専用ユーザーと小さなフォルダで受入試験を行ってください。

## 対象と保証範囲

アプリの実装は `0.1.0-rc.3` と同一で、配布アーカイブ内の差分はアプリの版番号だけです。

- 固定ランタイム: Nextcloud **35.0.3**、公式 Apache イメージ、PHP **8.5.12**。
- 想定ストレージ: 暗号化なしのローカルユーザーホーム。
- 対象範囲: 管理者が明示登録したユーザー／サブディレクトリ。
- 対象外・未保証: 外部ストレージ、暗号化、他の Nextcloud 版、全 DAV クライアント／機能の互換性、任意環境での本番適合。

`allowed_roots` はアプリの内容条件を適用する範囲であり、ユーザーの認可や App Password のアクセス範囲を狭めません。Nextcloud 標準の DAV 認証・アクセス制御を別途適切に構成してください。外部の書込み入口をアプリが自動で閉鎖することもありません。

## 文書

- [新規インストール](docs/install-nextcloud.md)
- [GA リリースノート](docs/ga-notes.md)
- [rc.3 からの更新と切戻し](docs/upgrade.md)

## ライセンスと由来

アプリは GNU AGPL v3 またはそれ以降（`AGPL-3.0-or-later`）です。[LICENSE](nextcloud_gateway/LICENSE) と [NOTICE](nextcloud_gateway/NOTICE.md) を参照してください。
