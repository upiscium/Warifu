# 稼働中サーバーを読み取り確認する

以下の `occ` と `curl` は設定・ファイルを書き換えません。値は実環境のものに置き換えます。

## サーバー設定

**実行場所：** Nextcloudアプリサーバーの Nextcloud ルート。Dockerならホストからアプリコンテナに入り、コンテナ内 `/var/www/html` をカレントディレクトリにします。DBコンテナではありません。Web実行ユーザーで実行します。

```sh
NC_USER='ユーザーID'
php occ status
php occ config:app:get nextcloud_gateway installed_version
php occ app:list
php occ config:app:get nextcloud_gateway scope_mode
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
```

期待する版は `0.3.0`。`scope_mode` は `all`、`selected`、`off` のいずれかです。`all` は全ユーザーのローカルホーム、`selected` は `allowed_roots` による内容条件の適用範囲、`off` は機能停止です。新規導入の既定は `all`。v0.2.0からの更新は旧 `experimental_enabled=yes` なら選択登録相当、それ以外（未設定を含む）は `off` です。更新時に全体へ自動拡張されません。管理者画面は **管理者設定 → 基本設定 → Warifu**（`/index.php/settings/admin/server`）。`allowed_roots` はNextcloudのアクセス制御・ACLではありません。

## 認証済みDAVの読み取り確認

更新直後は `scope_mode` キーがまだなく、`occ` が値を返さない場合があります。旧設定を読み替えた**実効モード**は上記の管理画面で確認できます。確認だけなら保存する必要はありません。

**実行場所：** クライアントPCの任意の作業ディレクトリ。curl 7.84以降を使います。TLS検証を無効にしないでください。App Passwordをプロンプト入力できるよう `--user` にパスワードを含めません。

```sh
NC_USER='ユーザーID'
DAV="https://cloud.example.org/remote.php/dav/files/$NC_USER"
curl -sS -X PROPFIND -H 'Depth: 0' -w '%{http_code}\n' -o /dev/null "$DAV/WarifuTest/"
curl -sS --user "$NC_USER" -X PROPFIND -H 'Depth: 0' -w '%{http_code} gateway=%header{x-nextcloud-gateway}\n' -o /dev/null "$DAV/WarifuTest/"
curl -sS --user "$NC_USER" -I -o /dev/null -w '%{http_code} gateway=%header{x-nextcloud-gateway} etag=%header{etag}\n' "$DAV/WarifuTest/small.txt"
```

URL、ユーザー、パスは実在する値に置換します。典型的には匿名要求 `401`、認証済み許可フォルダ `207` が期待されます。対象モードが有効な場合、ファイル応答には `X-Nextcloud-Gateway: experimental-1` と `"ncgw-sha256-` で始まる64桁SHA-256 ETagが現れます。実応答は構成に依存し、`occ` の成功だけではHTTP適用を確認できません。

外部ストレージ、暗号化、サブマウントは対象外です。ハッシュはファイル全体を読むので、サイズ上限未満でも累積ハッシュ予算に達し得ます。
