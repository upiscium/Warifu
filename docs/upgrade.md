# Warifu v0.1.0 から v0.2.0-rc.1 へ更新

候補は未署名です。[新規導入手順](install-nextcloud.md)と同じ方法で配布アーカイブを照合してください。対象ランタイム・ストレージが [README](../README.md) の範囲外なら停止してください。以下の `occ` はすべてNextcloudの `occ` があるディレクトリでWeb実行ユーザーとして実行します。

## 更新前

1. `php occ status`、`php occ config:app:get nextcloud_gateway installed_version`、`php occ app:list` と対象ユーザーの `allowed_roots` を記録します。
2. 全DAVクライアント、cron、外部writerを止め、進行中の書込みを完了させます。未送信クライアント編集も別途保護します。
3. `php occ maintenance:mode --on` とし、全Webワーカーを排出します。
4. DB、設定、データ、現在の `nextcloud_gateway` コードを同じ時点でバックアップし、隔離環境で復元可能であることを確認します。旧コードの退避先はWeb公開範囲・アプリ探索パスの外にします。
5. 更新アーカイブの SHA-256 を配布元の `SHA256SUMS` で検証します。不一致や未提供なら先へ進みません。

## コード置換と更新

同じファイルシステムの一時ディレクトリに展開し、`nextcloud_gateway/appinfo/info.xml` を確認します。既存アプリへのoverlayはせず、アプリディレクトリを探索パス外へ退避してから候補コードを配置します。所有者・権限を確認後、NextcloudルートでWeb実行ユーザーとして実行します。

```sh
php occ upgrade
php occ config:app:get nextcloud_gateway installed_version
php occ status
```

`--force` は付けません。失敗時は新しいコードを有効運用せず、復旧手順へ進みます。成功時は版 `0.2.0-rc.1`、DB状態、従来のアプリ有効状態・ユーザー設定を確認し、メンテナンスモードを解除します。合成ファイルの読み書き、SHA ETag、古い条件の412を実DAVで確認してからwriterを再開します。

## 安全なロールバック

- すばやく機能を止めるにはwriterを止め、`php occ config:app:set nextcloud_gateway experimental_enabled --value=no` を実行し、全ワーカーへ反映します。読み取り専用DAV確認で本文が保たれ、GatewayヘッダーがなくネイティブETagに戻ったことを見てからvalidatorを取り直します。これはデータ巻戻しではありません。
- 更新前の状態へ完全復旧する場合はwriterを停止し、メンテナンスモードで同一時点のDB・設定・データ・コードを一緒に復元します。整合性を確認するまで書込みを再開しません。
- 更新後DBに古いアプリコードだけを戻すin-place downgradeは禁止です。復元手順に確信がなければ保守を継続し、管理者の復旧手順に従ってください。
