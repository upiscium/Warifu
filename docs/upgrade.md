# v0.2.0 から v0.3.0 へ更新・復旧

対象は v0.2.0 からの手動更新です。未署名アーカイブを[新規導入手順](install-nextcloud.md)と同様に独立入手したチェックサムと照合してください。未置換・照合不能・不一致なら中止します。外部ストレージ、暗号化、サブマウントは対象外です。

## 更新前

1. **Nextcloudルート（アプリサーバー、Dockerならアプリコンテナ内 `/var/www/html`）、Web実行ユーザー：** `php occ status`、`php occ config:app:get nextcloud_gateway installed_version`、`php occ app:list` を実行し、現在版・有効状態・設定を記録します。現在版が `0.2.0` でなければこの手順を続けません。
2. DAVクライアント、cron、外部writerを止め、進行中の書込みを完了させます。クライアント側の未送信編集も保護します。
3. Nextcloudルートで `php occ maintenance:mode --on` を実行し、全Webワーカーを排出します。
4. DB、設定、データ、現在のアプリコードを同一時点でバックアップし、復元可能性を確認します。旧コードはWeb公開範囲とアプリ探索パスの外に保管します。
5. [アーカイブ照合](install-nextcloud.md#アーカイブ照合と配置)を行います。

## コード更新

**実行場所：** サーバー上でアプリ配置先が見えるシェル。同一ファイルシステム上の一時領域へ展開し、`nextcloud_gateway/appinfo/info.xml` を確認します。既存コードにoverlayせず、旧ディレクトリを探索パス外へ退避してから新コードを配置します。所有者・権限を確認してください。

**実行場所：** Nextcloudルート、Web実行ユーザー。

```sh
php occ upgrade
php occ config:app:get nextcloud_gateway installed_version
php occ config:app:get nextcloud_gateway scope_mode
php occ status
```

期待する版は `0.3.0`。`scope_mode` が未設定なら旧 `experimental_enabled=yes` は `selected` 相当、それ以外は `off` のままです。クライアントとcronの書込みを停止したまま `php occ maintenance:mode --off` とし、新しいコードで全Webワーカーを起動します。範囲を変えたい場合だけ **管理者設定 → 基本設定 → Warifu** で選んで保存します。合成ファイルでDAV応答を確認してからwriterを再開してください。

## 停止と完全復旧の違い

- **機能を停止するだけ：** writerを止め、設定画面で `off` を保存します。全ワーカーに反映されたことを確認します。これはDB・データ・コードを以前の状態へ戻す操作ではありません。
- **更新前へ完全復旧：** writerを停止し、メンテナンスモード下で同一時点のDB・設定・データ・アプリコードを一緒に復元します。整合性を確認するまで書込みを再開しません。

更新後DBに旧アプリコードだけを戻すin-place downgradeは禁止です。復元に確信がなければメンテナンスモードを維持し、管理者の復旧手順に従ってください。
