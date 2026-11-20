# Warifu v0.2.0 を新規導入する

このアーカイブは未署名で、手動導入を前提とします。署名検証やアプリストア経由の自動更新を期待しないでください。対象環境の適合性を配布物のメタデータと照合し、専用の非管理者ユーザーと専用フォルダを用意します。バックアップの復元可能性を事前に確認してください。

## アーカイブ確認と配置

**実行場所：** GNU/Linux上で、配布アーカイブと独立して入手したチェックサムファイルを置いたディレクトリ。配置先が見えるシェルで実行します（Dockerの場合は永続化された`custom_apps`）。値は環境に合わせて置換してください。

```sh
set -eu
ARCHIVE="$PWD/nextcloud_gateway-0.2.0.tar.gz"
printf '%s  %s\n' 'a48403b7dcb67947f57be734e4462c53ac9a175045b95aa14a753a6313f92766' "$ARCHIVE" | sha256sum --check -
sha256sum --check SHA256SUMS
APPDIR=/var/www/html/custom_apps  # 実際の登録済み・永続化されたアプリディレクトリ
test -d "$APPDIR"
test ! -L "$APPDIR"
target="$APPDIR/nextcloud_gateway"
test ! -e "$target"
test ! -L "$target"
stage=$(mktemp -d "$APPDIR/.ncgw-stage.XXXXXXXX")
tar --no-same-owner -xzf "$ARCHIVE" -C "$stage"
test -f "$stage/nextcloud_gateway/appinfo/info.xml"
mv -nT -- "$stage/nextcloud_gateway" "$target"
test ! -e "$stage/nextcloud_gateway"
rmdir "$stage"
```

`SHA256SUMS`には上記アーカイブ名の行があり、照合結果が`OK`である必要があります。未置換メタデータ、照合不能・不一致、配置先の競合では停止します。上書き、`--force`、既存ディレクトリへの展開はしません。展開後、Web実行ユーザーが読める所有者・権限を設定し、全ワーカーが同じコードを参照することを確認してください。

## 有効化

**実行場所：** Nextcloudルート。Web実行ユーザーとして実行します。専用ユーザーとフォルダは事前に用意してください。

```sh
php occ status
php occ app:enable nextcloud_gateway
NC_USER='専用ユーザーID'
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots '["WarifuTrial"]'
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
php occ config:app:set nextcloud_gateway experimental_enabled --value=yes
```

`WarifuTrial`は専用ユーザーのFiles画面で作成したフォルダ名に合わせます。`allowed_roots`はアクセス制御ではありません。App Passwordを発行し、TLS検証を有効にしたHTTPSでDAV接続してください。パスワードをコマンドやシェル履歴に含めないでください。

## 受入と切戻し

まず[読み取り専用確認](check-server.md)を行います。その後、本番データではなく新規の合成ファイルだけを用いて条件付きPUT、古いETagによる412、本文の読み戻しを確認します。想定外の結果ならwriterを再開しないでください。

機能を停止する場合はwriter停止後に、NextcloudルートでWeb実行ユーザーとして次を実行します。

```sh
php occ config:app:set nextcloud_gateway experimental_enabled --value=no
```

全ワーカーへの反映とDAV応答を確認し、クライアントにvalidatorを再取得させます。アプリ無効化や機能フラグ変更はファイルデータを以前の状態へ戻しません。完全復旧は[更新手順のロールバック](upgrade.md)を参照してください。
