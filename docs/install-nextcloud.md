# Warifu for Nextcloud を新規導入する（0.1.0-rc.3）

Warifu はプロジェクトの公開名です。検証済みのRCではアプリ名が「Nextcloud Gateway (experimental)」と表示されます。配置先と `occ` コマンドには `warifu` ではなくアプリID `nextcloud_gateway` を使ってください。

この手順は、標準の Files DAV で**専用ユーザーの1フォルダ**に内容SHA-256のETagを導入するためのものです。既にrc.3が稼働しているNASでは再実行しないでください。RCは未署名の手動導入用候補で、App Store配布版でもGAでもありません。

## 1. 導入できるかを判定する

- 検証対象は **Nextcloud 35.0.3** の固定コア（38ファイルを照合）、標準の非暗号化ローカルホーム、ファイルロック有効のDBまたは検証済みRedis構成です。実NASは公式Apacheイメージ、PHP 8.5.12、MariaDB 12.3.2、DBロックでした。外部ストレージ・共有リンク・シンボリックリンク・暗号化領域は選ばないでください。
- 上限は1ファイル64 MiB、1一覧512 entries（フォルダ自身を含む）、1要求のハッシュ処理256 MiB、協調的な時間制限30秒です。上限を超えるホーム全体を登録せず、小さなフォルダを選びます。
- **有効化はインスタンス全体です。** `filesystem` 型のため `app:enable --groups` では限定できません。`allowed_roots` はDAV動作の対象であり、アクセス制御ではありません。登録外にも初期化コストがあり、DB構成では共有ロックキャッシュの準備も行います。これを許容できないサーバーでは、別インスタンスで試してください。
- 導入前に対象ファイル・DB・設定を同じ時点でバックアップし、隔離先で読戻しとDB復元を確認します。クライアントの未送信編集と同期状態は別に保護し、切替中は対象writerを停止します。既存Gateway、過去のユーザー設定、同名ディレクトリがある場合は新規導入の対象外です。上書きやダウングレードはしません。

## 2. アーカイブを確認して配置する

使うのは `nextcloud_gateway-0.1.0-rc.3.tar.gz` です。`-source.tar.gz` は対応ソースの閲覧用で、インストール用ではありません。信頼できる経路で入手し、配布者のチェックサムと一致するまで展開しません。ハッシュだけでは配布者の署名の代わりにはなりません。

以下はGNU/Linuxでの例です。`APPDIR` を**実際にNextcloudに登録済みの書込み可能なアプリディレクトリ**に変更してください。コンテナではホストの任意のパスではなく、永続化されている `custom_apps` に配置し、全Webワーカーが同じコードを参照するようにします。

```sh
set -eu
ARCHIVE="$PWD/nextcloud_gateway-0.1.0-rc.3.tar.gz"
APPDIR=/var/www/html/custom_apps
printf '%s  %s\n' fc109382044df1cf1d3be733a53730ef707ec691777c482318dbd3578bb689a0 "$ARCHIVE" | sha256sum --check -
test -d "$APPDIR"
test ! -L "$APPDIR"
target="$APPDIR/nextcloud_gateway"
test ! -e "$target"
test ! -L "$target"
stage=$(mktemp -d "$APPDIR/.ncgw-stage.XXXXXXXX")
tar --no-same-owner -xzf "$ARCHIVE" -C "$stage"
test -f "$stage/nextcloud_gateway/appinfo/info.xml"
mv -nT -- "$stage/nextcloud_gateway" "$target"
test ! -e "$stage/nextcloud_gateway"   # 他の配置と衝突したらここで停止
rmdir "$stage"                       # 自分が作った空ディレクトリのみ
```

Web実行ユーザーがファイルを読めることを確認します。`chmod 777`、署名・互換性検査の無効化、`--force` は不要です。組織の方針が署名必須なら未署名RCの導入は中止してください。

## 3. 専用ユーザーを登録し、最後に機能を有効化する

管理画面で管理者権限を持たない新規ユーザーを作成し、そのユーザーのFiles画面で `GatewayTrial` フォルダを作ります。以下はNextcloudルートで**Web実行ユーザーとして**実行します。公式Dockerイメージなら各 `php occ …` を `docker exec --user www-data <確認済みのappコンテナ> php /var/www/html/occ …` と読み替えます。

```sh
NC_USER='gateway-trial'       # 自分が作成した専用ユーザーのUID
php occ status
php occ app:enable nextcloud_gateway
# 新規インストールではexperimental_enabledの既定はno。既存のyesがあれば停止。
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots '["GatewayTrial"]'
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
# 上の戻り値と、他ユーザーに旧設定がないことを確認してから最後に切り替える。
php occ config:app:set nextcloud_gateway experimental_enabled --value=yes
```

ブラウザーは通常のWebログインを使います。DAVにはこのユーザーの「個人設定 → セキュリティ」で新規App Passwordを発行します。App Password・Cookie・設定ファイルを貼り付けたり、Gitやシェル履歴に保存したりしないでください。

## 4. 実際のHTTPSで受入試験をする

`status.php` のHTTP 200や `occ` の成功だけでは合格ではありません。以下の `curl --user "$NC_USER"` はApp Passwordを対話入力します。`-k` やリダイレクト追従は使いません。例のUID・フォルダはURLエンコード不要のASCII名です。

```sh
DAV='https://cloud.example.org/remote.php/dav/files/gateway-trial'
# curl 7.84以降。Cookieなどを含み得る全ヘッダーではなく、判定に必要な値だけ表示する。
fmt='%{http_code} gateway=%header{x-nextcloud-gateway}\n'
curl -sS -X PROPFIND -H 'Depth: 0' -w "$fmt" -o /dev/null "$DAV/GatewayTrial/"
curl -sS --user "$NC_USER" -X PROPFIND -H 'Depth: 0' -w "$fmt" -o /dev/null "$DAV/GatewayTrial/"
```

| 確認 | 期待結果 |
| --- | --- |
| 匿名／専用App Passwordで登録フォルダに `PROPFIND` | 順に401／207。認証時は `X-Nextcloud-Gateway: experimental-1` |
| 同じユーザーで登録外フォルダを `PROPFIND` | 通常DAVの207、Gatewayヘッダーなし |
| 新規の合成ノートに `If-None-Match: *` でPUTし、GET | 201 → 200。GETのETagは `"ncgw-sha256-<取得したバイト列のSHA-256>"` |
| そのETagを `If-Match` に指定し別の内容でPUT | 204。新しいGETの本文とETagが一致 |
| **古い**ETagで再度PUTし、GET | 412。直前に保存した内容が残る。無条件PUTで再試行しない |

合わなければwriterを再開せず、対象UID・フォルダ名・ワーカー間の配置／キャッシュを確認します。実データで削除試験はしません。検証済みのクライアントと対象外の操作は[RCの検証範囲](rc-notes.md)を参照してください。

## 5. 切り戻す

対象writerを止め、`php occ config:app:set nextcloud_gateway experimental_enabled --value=no` で機能を停止し、必要なら `php occ app:disable nextcloud_gateway` でアプリも無効化します。全ワーカーへの反映後、同じ認証済みDAVのGETでGatewayヘッダーが消え、ネイティブETagに戻ったことを確認します。クライアントはvalidatorを取り直してから再開し、不要になったApp Passwordは失効させます。アプリの無効化は保存済みファイルを以前の内容に戻す操作ではありません。
