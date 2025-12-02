# notion-bookshelf-importer

Notion Automations から渡される JSON 情報を基にタイトル・価格・著者情報などを抽出し、Notion の本棚データベースへ登録するバックエンドアプリ。

## Webhook 仕様

Notion から呼び出される Laravel 製の webhook を `routes/api.php` に定義しています。`POST /api/webhook/notion/books` に対して以下の形式でリクエストしてください。

- ヘッダー: `X-Webhook-Key` に `.env` の `WEBHOOK_AUTH_KEY` と同じ値を設定（時刻署名不要、`hash_equals` で比較）。
- ボディ: Notion Automations から届く page オブジェクトのペイロードをそのまま送ってください。`data.id` から page_id を、`data.properties.情報.rich_text` に含まれる JSON 文字列から書誌情報を取り出して処理します。

```json
{
  "source": {
    "type": "automation"
  },
  "data": {
    "object": "page",
    "id": "826eb1f6a6fb45b38d649ef82911bb61",
    "properties": {
      "ID": {
        "type": "unique_id",
        "unique_id": {
          "prefix": "BOOK",
          "number": 408
        }
      },
      "情報": {
        "type": "rich_text",
        "rich_text": [
          {
            "type": "text",
            "text": {
              "content": "{
"title": "Example Book",
"author": "Author Name",
"kindle_price": "1200円",
"image_url": "https://example.com/image.jpg"
}"
            }
          }
        ]
      }
    }
  }
}
```

リクエストで受け取った `ID` が数値の場合は Unique ID とみなし、`data_sources/{NOTION_DATA_SOURCE_ID}/query` を呼び出して検索結果の page_id を使って更新します。`NOTION_DATA_SOURCE_ID` が未設定、あるいは該当レコードが見つからない場合は 4xx を返します。数値以外の値が渡された場合はそれを page_id として直接更新します。

## 環境変数

`.env.example` をコピーして `.env` を用意してください。

- `WEBHOOK_AUTH_KEY`: Notion からのリクエストヘッダーに設定する認証キー。
- `DOWNLOAD_WEBHOOK_IMAGE`: `true` の場合、Webhook で渡された画像 URL を取得し、`public/imgs` に保存したパスを Notion に登録します。
- `NOTION_API_KEY`: Notion 公式 API の統合トークン。
- `NOTION_DATA_SOURCE_ID`: Data Source ID（データベース ID ではなく data source を指定）。Unique ID プロパティから page_id を引くために Query する際に利用します。必須です。
- `NOTION_VERSION`: API バージョン。仕様通り `2025-09-03` をデフォルトに設定。
- `NOTION_BASE_URL`: Notion API のベース URL（`https://api.notion.com/v1` のようにパスなしで設定し、`/pages` などのサフィックスは付けない）。
- `NOTION_PROPERTY_MAPPING`: Webhook で更新するプロパティのマッピング JSON。キー名が抽出結果のキー、`name` が Notion 側のプロパティ名、`type` がプロパティ種別（`title` / `select` / `date` / `number` / `image`）。サンプルのデフォルト値は抽出結果の 4 項目（`name` / `author` / `price` / `image`）に対応しています。追加で日付などを扱いたい場合は、抽出処理を拡張したうえで `{"date":{"name":"購入日","type":"date"}}` のように追記してください。

### ローカルテスト用サンプル JSON

リポジトリ直下の `samples/` にローカルでの動作確認に使えるサンプル JSON を用意しています。

- `webhook_request.json`: `POST /api/webhook/notion/books` に送信する想定のリクエストボディ。
- `extraction_response.json`: Amazon 商品ページから抽出した結果サンプル（`name` / `author` / `price` / `image`）。
- `property_mapping.json`: `.env` の `NOTION_PROPERTY_MAPPING` に設定できるサンプルマッピング（抽出結果の 4 項目のみをマッピング）。

## テスト実行方法

1. Composer で依存関係をインストールします。

   ```bash
   composer install
   ```

2. `php artisan test` を実行します（オプション `--filter` や `--testsuite` も利用可能です）。

## 処理フロー

1. `NotionWebhookController` でヘッダー認証とバリデーションを実施。
2. Notion 側の「情報」プロパティに含まれる JSON 文字列からタイトル・著者・金額・画像 URL を抽出。返却キーは `name` / `author` / `price` / `image` に固定。
3. `NotionService` が `NOTION_PROPERTY_MAPPING` を基にプロパティ payload を構築し、`PATCH /pages/{page_id}` で Notion に反映。

### Notion ページの更新フロー（2025-09-03 版）

Notion のレコード更新は page_id に対してのみ行われます。Unique ID などのプロパティから page_id を求める場合は以下の 2 ステップで対応してください。

1. `POST /v1/data_sources/{data_source_id}/query` で ID プロパティをフィルタに指定して対象ページを検索し、レスポンスに含まれる `results[*].id` から page_id を取得する。
2. 取得した page_id に対して `PATCH /v1/pages/{page_id}` を呼び、`properties` に更新内容を渡す。

## プロパティの更新ロジック

- title: Plain text で上書き。
- select: 該当オプションが存在しない場合 Notion 側で自動生成されます。
- date: `YYYY-MM-DD` 形式で指定してください。
- number: 数値（例: 1800.0）。
- image: `DOWNLOAD_WEBHOOK_IMAGE` が `true` の場合はダウンロードしたローカルファイルの URL を、`false` の場合は外部 URL のまま `files` プロパティとして登録します。

## エラーハンドリング

- 認証エラーは 401 を返します。
- Notion へのリクエストが失敗した場合は例外を投げ、Laravel の例外ハンドラ経由でエラー応答します。ログにもレスポンス body を出力します。
