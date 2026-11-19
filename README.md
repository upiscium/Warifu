# Warifu for Nextcloud

Warifu（割符）は、Nextcloud標準のFiles WebDAVに内容SHA-256のETagを追加するサーバーアプリです。クライアントが `If-Match` を送ると、読み取った版と現在の版が一致する場合だけ書き戻せます。無条件PUTを禁止する機能ではありません。

**0.1.0-rc.3 は手動導入向けの未署名リリース候補です。** アプリIDは `nextcloud_gateway`、Nextcloud上の表示名は `Nextcloud Gateway (experimental)` のままです。

- [導入・受入試験・切戻し](docs/install-nextcloud.md)
- [RCの検証範囲と既知の制限](docs/rc-notes.md)
- [アプリのソース](nextcloud_gateway/) · [ライセンス](nextcloud_gateway/LICENSE) · [由来・著作権表示](nextcloud_gateway/NOTICE.md)

対象はNextcloud **35.0.3** の固定ランタイムと非暗号化のローカルホームです。専用ユーザー・専用フォルダから始めてください。`allowed_roots` は認可境界ではなく、App Passwordのアクセス権を狭めません。
