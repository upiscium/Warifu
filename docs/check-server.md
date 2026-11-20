# 稼働中サーバーを読み取り専用で確認する

以下は設定・ファイルを書き換えません。`occ` は管理者がNextcloudルートでWeb実行ユーザーとして実行します。Dockerの場合、Nextcloudアプリコンテナ（DBコンテナではない）の `/var/www/html` がルートです。

## サーバー側の状態

**実行場所：** Nextcloudルート。Dockerならホスト上でアプリコンテナに入り、コンテナ内の `/var/www/html` をカレントディレクトリにします。

```sh
NC_USER='専用ユーザーID'
php occ status
php occ config:app:get nextcloud_gateway installed_version
php occ app:list
php occ config:app:get nextcloud_gateway experimental_enabled
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
```

`status` の `installed`、`maintenance`、`needsDbUpgrade` とアプリの有効状態・版・機能フラグ・ユーザー設定を、管理者が期待する状態と照合してください。値が異なる場合は、読み取り確認だけで止め、変更する前に管理者へ確認します。

## 認証済みDAVの読み取り確認

**実行場所：** クライアントPCの任意のディレクトリ。curl 7.84以降を使用します。ホスト名、ユーザーID、登録フォルダとファイル名は実在する値に置換してください。`--user` にパスワードを含めず、プロンプトでApp Passwordを入力します。

```sh
NC_USER='専用ユーザーID'
DAV="https://cloud.example.org/remote.php/dav/files/$NC_USER"
curl -sS -X PROPFIND -H 'Depth: 0' -w '%{http_code}\n' -o /dev/null "$DAV/WarifuTrial/"
curl -sS --user "$NC_USER" -X PROPFIND -H 'Depth: 0' -w '%{http_code} gateway=%header{x-nextcloud-gateway}\n' -o /dev/null "$DAV/WarifuTrial/"
curl -sS --user "$NC_USER" -I -o /dev/null -w '%{http_code} gateway=%header{x-nextcloud-gateway} etag=%header{etag}\n' "$DAV/WarifuTrial/small.txt"
```

パス例は置換必須です。未認証要求は通常401、認証済みの許可フォルダは207が期待されます。v0.2.0の機能が有効な場合、Gatewayヘッダーと `"ncgw-sha256-` に続く64桁のSHA-256 ETagが期待値です。実際の応答は環境・設定に依存します。HTTP 207や`occ`の成功だけでは機能適用の確認になりません。この読み取り確認は書込み受入試験の代用ではありません。
