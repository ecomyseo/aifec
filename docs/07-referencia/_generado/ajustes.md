# Ajustes

Los ajustes que declaran los `settings.php`, con su pestaña, su tipo y su valor de fábrica. Un secreto no enseña nunca su valor. Los campos que no guardan nada (`heading`, `html`, `store => false`) no salen.

Generado con `php aifec docs:reference`. No lo edites a mano.

| Clave | Módulo | Pestaña | Tipo | Por defecto | Etiqueta |
| --- | --- | --- | --- | --- | --- |
| ai.advanced.base_url | ai | Provider and API key | url | "" | Base address (OpenAI-compatible only) |
| ai.advanced.key | ai | Provider and API key | secret | (secret) | API key |
| ai.advanced.model | ai | Provider and API key | text | "" | Model |
| ai.advanced.price_in | ai | Spending | number | 0 | Advanced: input |
| ai.advanced.price_out | ai | Spending | number | 0 | Advanced: output |
| ai.advanced.provider | ai | Provider and API key | select | "gemini" | Provider |
| ai.daily_limit_calls | ai | Spending | number | 500 | Daily cap in calls |
| ai.daily_limit_cents | ai | Spending | money | 200 | Daily cap |
| ai.daily_limit_tokens | ai | Spending | number | 0 | Daily token cap |
| ai.economy.base_url | ai | Provider and API key | url | "" | Base address (OpenAI-compatible only) |
| ai.economy.key | ai | Provider and API key | secret | (secret) | API key |
| ai.economy.model | ai | Provider and API key | text | "" | Model |
| ai.economy.price_in | ai | Spending | number | 0 | Economy: input |
| ai.economy.price_out | ai | Spending | number | 0 | Economy: output |
| ai.economy.provider | ai | Provider and API key | select | "gemini" | Provider |
| ai.enabled | ai | General | toggle | false | Turn the AI on |
| ai.monthly_limit_cents | ai | Spending | money | 0 | Monthly spending cap |
| ai.show_notice | ai | General | toggle | false | Tell visitors that part of the text was written with AI help |
| ai.standard.base_url | ai | Provider and API key | url | "" | Base address (OpenAI-compatible only) |
| ai.standard.key | ai | Provider and API key | secret | (secret) | API key |
| ai.standard.model | ai | Provider and API key | text | "" | Model |
| ai.standard.price_in | ai | Spending | number | 0 | Standard: input |
| ai.standard.price_out | ai | Spending | number | 0 | Standard: output |
| ai.standard.provider | ai | Provider and API key | select | "gemini" | Provider |
| ai.timeout | ai | Spending | number | 30 | Seconds to wait for an answer |
| api.agent_orders | api | Orders | select | "code" | Orders placed by AI agents |
| api.code_whatsapp | api | Orders | toggle | false | Send the code by WhatsApp too |
| api.cors_origins | api | General | textarea | "" | Origins allowed to use the cart from a browser |
| api.enabled | api | General | toggle | true | Turn the API on |
| api.links | api | General | toggle | true | Let assistants that can only read pages buy with links |
| api.max_order_cents | api | Orders | money | 0 | Highest order a program can place |
| api.max_orders_per_hour | api | Orders | number | 10 | Orders per key (or per address without a key) and hour |
| api.rate_per_minute | api | Limits | number | 120 | Requests per minute without a key |
| api.rate_per_minute_key | api | Limits | number | 600 | Requests per minute with a key |
| api.whatsapp_only | api | Orders | textarea | "" | Only send WhatsApp to these phones |
| api.whatsapp_prefix | api | Orders | text | "34" | Country code for phones written without one |
| api.whatsapp_provider | api | Orders | select | "mensajero" | WhatsApp system |
| api.whatsapp_template | api | Orders | text | "" | 360dialog: approved template |
| api.whatsapp_template_button | api | Orders | toggle | false | 360dialog: the template has a copy-code button |
| api.whatsapp_template_lang | api | Orders | text | "es" | 360dialog: template language |
| api.whatsapp_template_vars | api | Orders | text | "code" | 360dialog: values for {{1}}, {{2}}… |
| api.whatsapp_token | api | Orders | secret | (secret) | WhatsApp gateway key |
| api.whatsapp_url | api | Orders | text | "" | WhatsApp gateway address |
| assistant.about | assistant | What it tells customers | textarea | "" | What the assistant should know about your shop |
| assistant.checkout | assistant | Orders in the chat | toggle | true | Let the assistant take the order in the chat |
| assistant.enabled | assistant | General | toggle | true | Show the assistant in the shop |
| assistant.greeting | assistant | General | text | "" | Extra text for the greeting |
| assistant.hours | assistant | What it tells customers | text | "" | Opening hours |
| assistant.instructions | assistant | What it tells customers | textarea | "" | Specific instructions for the assistant |
| assistant.max_messages | assistant | Limits | number | 30 | Messages per conversation |
| assistant.max_order_cents | assistant | Orders in the chat | money | 0 | Highest order the assistant can take |
| assistant.name | assistant | General | text | "" | Assistant name |
| assistant.quality | assistant | General | select | "normal" | Answer quality |
| assistant.retention_days | assistant | Limits | number | 30 | Days to keep the conversations |
| assistant.returns_text | assistant | What it tells customers | textarea | "" | Returns policy |
| assistant.tone | assistant | General | select | "friendly" | Tone |
| catalog.card_options | catalog | Listings | toggle | true | Choose the combination and the quantity in the listings |
| catalog.card_options_max | catalog | Listings | number | 24 | Combinations that fit in a card |
| catalog.low_stock | catalog | Listings | number | 5 | Warn when the stock is at or below |
| catalog.new_days | catalog | Listings | number | 30 | Days a product counts as new |
| catalog.per_page | catalog | Listings | number | 24 | Products per page |
| catalog.related | catalog | Listings | toggle | true | Show «You may also like» on the product page and in the cart |
| catalog.show_out_of_stock | catalog | Listings | toggle | true | Show the products that are out of stock |
| urls.brand | catalog | Addresses | slug | "marca" | Word for brands |
| urls.category | catalog | Addresses | slug | "categoria" | Word for categories |
| urls.product | catalog | Addresses | slug | "producto" | Word for products |
| cms.posts_per_page | cms | Blog | number | 10 | Posts per page |
| urls.post | cms | Addresses | slug | "blog" | Word for the blog and its posts |
| urls.term_category | cms | Addresses | slug | "categoria" | Word for blog categories |
| urls.term_tag | cms | Addresses | slug | "etiqueta" | Word for blog tags |
| consent.enabled | consent | Notice | toggle | true | Show the cookie notice |
| consent.log | consent | Policy and proof | toggle | true | Keep the proof of each decision (5 years) |
| consent.message | consent | Notice | textarea |  | Message |
| consent.policy_url | consent | Notice | url |  | Cookie policy page |
| consent.title | consent | Notice | text |  | Title |
| consent.version | consent | Policy and proof | number | 1 | Policy version |
| copilot.ai | copilot | General | toggle | true | Use the AI to understand the orders |
| copilot.history_days | copilot | General | number | 90 | Days to keep the history |
| copilot.max_rows | copilot | General | number | 500 | Most records one change can touch |
| api.rate_per_minute | core | Security | number | 120 | API requests per minute |
| cache.pages | core | Performance | toggle | true | Page cache |
| i18n.languages | core | Languages and currency | tags | [] | Languages |
| security.csp | core | Security | select | "strict" | Content security policy |
| security.csp_sources | core | Security | textarea | "" | Other sites the pages may load from |
| security.proxy_header | core | Security | select | "x-forwarded-for" | Header with the visitor address |
| security.trusted_proxies | core | Security | textarea | "" | Trusted proxies |
| shop.currency | core | Languages and currency | select | "EUR" | Currency |
| shop.currency_symbol | core | Languages and currency | text | "€" | Currency symbol |
| store.address | core | Identity | textarea | "" | Address |
| store.email | core | Identity | email | "" | Contact email |
| store.icon | core | Identity | image | 0 | Site icon |
| store.legal_name | core | Identity | text | "" | Legal name |
| store.logo | core | Identity | image | 0 | Logo |
| store.name | core | Identity | text | "AIFEC" | Shop name |
| store.phone | core | Identity | text | "" | Phone |
| store.vat_id | core | Identity | text | "" | Tax ID |
| urls.trailing_slash | core | Addresses | toggle | false | End the addresses with a slash |
| customers.allow_register | customers | Accounts | toggle | true | Allow customers to create an account |
| customers.legacy_cookie_key | customers | Migrated accounts | secret | (secret) | Cookie key of the old PrestaShop |
| customers.magic_link | customers | Accounts | toggle | true | Allow signing in with a link sent by email |
| datalayer.ads_category | datalayer | Google Ads | select | "marketing" | Needs consent for |
| datalayer.ads_enhanced | datalayer | Google Ads | toggle | false | Enhanced conversions (email and phone, hashed) |
| datalayer.ads_id | datalayer | Google Ads | text |  | Conversion ID |
| datalayer.ads_label | datalayer | Google Ads | text |  | Purchase conversion label |
| datalayer.custom_marketing | datalayer | Your own code | code |  | Code for visitors who accepted marketing |
| datalayer.custom_preferences | datalayer | Your own code | code |  | Code for visitors who accepted preferences |
| datalayer.custom_statistics | datalayer | Your own code | code |  | Code for visitors who accepted statistics |
| datalayer.enabled | datalayer | General | toggle | true | Measure the shop |
| datalayer.exclude_staff | datalayer | General | toggle | true | Do not measure people who are signed in to the panel |
| datalayer.ga4_api_secret | datalayer | Google Analytics | secret | (secret) | API secret (server events) |
| datalayer.ga4_category | datalayer | Google Analytics | select | "statistics" | Needs consent for |
| datalayer.ga4_id | datalayer | Google Analytics | text |  | Measurement ID |
| datalayer.gtm_category | datalayer | Tag Manager | select | "statistics" | Needs consent for |
| datalayer.gtm_id | datalayer | Tag Manager | text |  | Container ID |
| datalayer.meta_category | datalayer | Meta Pixel | select | "marketing" | Needs consent for |
| datalayer.meta_pixel | datalayer | Meta Pixel | text |  | Pixel ID |
| datalayer.meta_test_code | datalayer | Meta Pixel | text |  | Test event code |
| datalayer.meta_token | datalayer | Meta Pixel | secret | (secret) | Access token (server events) |
| datalayer.meta_version | datalayer | Meta Pixel | text |  | API version |
| datalayer.server_events | datalayer | General | toggle | false | Send purchases and refunds from the server |
| datalayer.tiktok_category | datalayer | TikTok | select | "marketing" | Needs consent for |
| datalayer.tiktok_pixel | datalayer | TikTok | text |  | Pixel ID |
| datalayer.uet_category | datalayer | Microsoft Ads | select | "marketing" | Needs consent for |
| datalayer.uet_id | datalayer | Microsoft Ads | text |  | UET tag ID |
| datalayer.value_with_tax | datalayer | General | toggle | false | Prices in the events include tax |
| erp.export_max_rows | erp | Files | number | 50000 | Rows an export can have at most |
| erp.folder_days | erp | Files | number | 14 | Days a file stays in the private output folder |
| erp.idempotency_hours | erp | General | number | 24 | Hours an Idempotency-Key is remembered |
| erp.keep_days | erp | Files | number | 7 | Days a run file can be downloaded |
| erp.outbound | erp | General | toggle | false | Allow outgoing messages |
| erp.rate_per_minute | erp | General | number | 300 | API requests per minute and key |
| gm_aviso_ia.categories | gm-aviso-ia | General | text | "" | Only in these categories |
| gm_aviso_ia.enabled | gm-aviso-ia | General | toggle | true | Show the notice |
| gm_aviso_ia.footer | gm-aviso-ia | General | toggle | false | Short notice in the footer of every page |
| gm_aviso_ia.footer_text | gm-aviso-ia | Texts | text | "" | Footer text |
| gm_aviso_ia.link_label | gm-aviso-ia | Texts | text | "" | Link text |
| gm_aviso_ia.page_id | gm-aviso-ia | Texts | number | 0 | Information page |
| gm_aviso_ia.pages | gm-aviso-ia | General | toggle | true | Also on pages and blog posts |
| gm_aviso_ia.placement | gm-aviso-ia | General | select | "summary" | Where in the product page |
| gm_aviso_ia.scope | gm-aviso-ia | General | select | "all" | Where it appears |
| gm_aviso_ia.text | gm-aviso-ia | Texts | textarea | "" | Text |
| gm_aviso_ia.title | gm-aviso-ia | Texts | text | "" | Title |
| gm_aviso_ia.url | gm-aviso-ia | Texts | text | "textos-con-ia" | Address of the page of this plugin |
| gm_legal_producto.checkout_blocks | gm-legal-producto | Where they appear | toggle | true | Show the texts in the checkout |
| gm_legal_producto.placement | gm-legal-producto | Where they appear | select | "summary" | In the product page |
| gm_legal_producto.version | gm-legal-producto | Withdrawal waiver | text | "" | Version of the texts |
| gm_legal_producto.waiver_enabled | gm-legal-producto | Withdrawal waiver | toggle | true | Ask about the waiver when the cart has digital products |
| gm_legal_producto.waiver_text | gm-legal-producto | Withdrawal waiver | textarea | "" | Text of the box |
| gm_licencias.rate_per_address | gm-licencias | Checking API | number | 30 | Checks per minute and address |
| gm_licencias.rate_per_key | gm-licencias | Checking API | number | 10 | Checks per minute and key |
| gm_licencias.signing_key | gm-licencias | Signed keys | secret | (secret) | Signing key |
| gm_proximamente.badge | gm-proximamente | Texts | text | "" | Badge text |
| gm_proximamente.intro | gm-proximamente | Texts | text | "" | Text of the form |
| gm_proximamente.rate_per_hour | gm-proximamente | Limits | number | 5 | Requests per hour and address |
| gm_proximamente.url | gm-proximamente | Limits | text | "proximamente" | Base address of the form |
| hello_aifec.default_country | hello-aifec | General | text | "" | Default country |
| import.import_images | import | Defaults | toggle | true | Bring the images |
| import.keep_urls | import | Defaults | toggle | true | Keep the old addresses |
| import.overwrite_local | import | Defaults | toggle | false | Overwrite what was edited here |
| import.step_records | import | Batches | number | 50 | Records per batch |
| import.step_seconds | import | Batches | number | 20 | Seconds per batch |
| invoices.attach | invoices | General | toggle | true | Attach the invoice to the order confirmation email |
| invoices.auto | invoices | General | toggle | true | Issue the invoice when an order is paid, and the credit note when money is refunded |
| invoices.credit_format | invoices | Numbering | text | "{series}{year}-{number:5}" | Credit note number format |
| invoices.credit_series | invoices | Numbering | text | "R" | Credit note series |
| invoices.format | invoices | Numbering | text | "{series}{year}-{number:5}" | Invoice number format |
| invoices.legal_text | invoices | Legal text | textarea |  | Text at the bottom of the invoice |
| invoices.series | invoices | Numbering | text | "F" | Invoice series |
| jobs.keep_done_days | jobs | Retention | number | 7 | Days to keep finished jobs |
| jobs.keep_failed_days | jobs | Retention | number | 30 | Days to keep failed jobs |
| mail.from_email | mail | Sender | email | "" | Sender email |
| mail.from_name | mail | Sender | text | "" | Sender name |
| mail.keep_days | mail | Log | number | 90 | Days to keep the content of each email |
| mail.mode | mail | Mode | select | "hold" | Email mode |
| mail.redirect_to | mail | Mode | email | "" | Test address |
| mail.reply_to | mail | Sender | email | "" | Reply to |
| mail.smtp_encryption | mail | Sending method | select | "tls" | Encryption |
| mail.smtp_host | mail | Sending method | text | "" | SMTP server |
| mail.smtp_password | mail | Sending method | secret | (secret) | SMTP password |
| mail.smtp_port | mail | Sending method | number | 587 | SMTP port |
| mail.smtp_timeout | mail | Sending method | number | 15 | Timeout |
| mail.smtp_user | mail | Sending method | text | "" | SMTP user |
| mail.system_always | mail | Mode | toggle | true | Always send password resets and test emails |
| mail.transport | mail | Sending method | select | "mail" | Method |
| mcp.enabled | mcp | General | toggle | true | Turn the MCP server on |
| mcp.tools | mcp | Tools | checks | ["search_products","get_product","list_categories","check… | Tools the assistants can use |
| media.max_mb | media | Images | number | 8 | Largest file (MB) |
| media.webp_quality | media | Images | number | 82 | WebP quality |
| orders.download_days | orders | Downloads | number | 0 | Days a download stays valid |
| orders.download_limit | orders | Downloads | number | 0 | Downloads allowed per file |
| orders.guest_checkout | orders | General | toggle | true | Allow buying without an account |
| orders.number_format | orders | General | text | "{year}-{number:6}" | Order number format |
| orders.payment_order | orders | Payment methods | text |  | Order of the payment methods |
| orders.pending_minutes | orders | General | number | 120 | Minutes before an unpaid order is cancelled |
| orders.require_vat_id | orders | General | toggle | false | Ask for the tax ID in the invoice details |
| orders.terms_page_id | orders | General | relation |  | Terms and conditions page |
| payments.cod.countries | payments-cod | Where | tags | ["ES"] | Countries |
| payments.cod.description | payments-cod | Texts | text |  | Description |
| payments.cod.fee | payments-cod | Fee | money | 0 | Fixed fee |
| payments.cod.max_total | payments-cod | Where | money | 0 | Highest order total |
| payments.cod.min_fee | payments-cod | Fee | money | 0 | Minimum fee |
| payments.cod.percent | payments-cod | Fee | percent | 0 | Percentage of the order total |
| payments.cod.title | payments-cod | Texts | text |  | Title |
| payments.paypal.applepay_domain_file | payments-paypal | Payment methods | textarea | "" | Apple Pay domain file |
| payments.paypal.client_id_live | payments-paypal | Keys | text | "" | Client ID (live) |
| payments.paypal.client_id_sandbox | payments-paypal | Keys | text | "" | Client ID (sandbox) |
| payments.paypal.environment | payments-paypal | General | select | "sandbox" | Environment |
| payments.paypal.paylater_message | payments-paypal | Payment methods | toggle | false | Show the instalments message next to the amount |
| payments.paypal.position_apple_pay | payments-paypal | Payment methods | number | 12 | Order of Apple Pay |
| payments.paypal.position_bancontact | payments-paypal | Payment methods | number | 3 | Order of Bancontact |
| payments.paypal.position_blik | payments-paypal | Payment methods | number | 4 | Order of BLIK |
| payments.paypal.position_card | payments-paypal | Payment methods | number | 2 | Order of the card |
| payments.paypal.position_card_fields | payments-paypal | Payment methods | number | 11 | Order of the card on the payment page |
| payments.paypal.position_eps | payments-paypal | Payment methods | number | 5 | Order of eps |
| payments.paypal.position_google_pay | payments-paypal | Payment methods | number | 13 | Order of Google Pay |
| payments.paypal.position_ideal | payments-paypal | Payment methods | number | 6 | Order of iDEAL |
| payments.paypal.position_mybank | payments-paypal | Payment methods | number | 7 | Order of MyBank |
| payments.paypal.position_p24 | payments-paypal | Payment methods | number | 8 | Order of Przelewy24 |
| payments.paypal.position_paylater | payments-paypal | Payment methods | number | 10 | Order of Pay Later |
| payments.paypal.position_paypal | payments-paypal | Payment methods | number | 1 | Order of PayPal |
| payments.paypal.position_trustly | payments-paypal | Payment methods | number | 9 | Order of Trustly |
| payments.paypal.position_venmo | payments-paypal | Payment methods | number | 14 | Order of Venmo |
| payments.paypal.secret_live | payments-paypal | Keys | secret | (secret) | Secret (live) |
| payments.paypal.secret_sandbox | payments-paypal | Keys | secret | (secret) | Secret (sandbox) |
| payments.paypal.source_apple_pay | payments-paypal | Payment methods | toggle | false | Apple Pay |
| payments.paypal.source_bancontact | payments-paypal | Payment methods | toggle | false | Bancontact |
| payments.paypal.source_blik | payments-paypal | Payment methods | toggle | false | BLIK |
| payments.paypal.source_card | payments-paypal | Payment methods | toggle | true | Card through PayPal |
| payments.paypal.source_card_fields | payments-paypal | Payment methods | toggle | false | Credit or debit card on the payment page |
| payments.paypal.source_eps | payments-paypal | Payment methods | toggle | false | eps |
| payments.paypal.source_google_pay | payments-paypal | Payment methods | toggle | false | Google Pay |
| payments.paypal.source_ideal | payments-paypal | Payment methods | toggle | false | iDEAL |
| payments.paypal.source_mybank | payments-paypal | Payment methods | toggle | false | MyBank |
| payments.paypal.source_p24 | payments-paypal | Payment methods | toggle | false | Przelewy24 |
| payments.paypal.source_paylater | payments-paypal | Payment methods | toggle | false | Pay Later with PayPal |
| payments.paypal.source_paypal | payments-paypal | Payment methods | toggle | true | PayPal |
| payments.paypal.source_trustly | payments-paypal | Payment methods | toggle | false | Trustly |
| payments.paypal.source_venmo | payments-paypal | Payment methods | toggle | false | Venmo |
| payments.paypal.title | payments-paypal | General | text | "" | Payment title |
| payments.paypal.webhook_id_live | payments-paypal | Keys | text | "" | Webhook ID (live) |
| payments.paypal.webhook_id_sandbox | payments-paypal | Keys | text | "" | Webhook ID (sandbox) |
| payments.redsys.bizum | payments-redsys | General | toggle | false | Offer Bizum |
| payments.redsys.bizum_title | payments-redsys | General | text | "" | Bizum title |
| payments.redsys.environment | payments-redsys | General | select | "test" | Environment |
| payments.redsys.merchant_code | payments-redsys | General | text | "" | Merchant code |
| payments.redsys.secret_live | payments-redsys | Keys | secret | (secret) | Secret key (live) |
| payments.redsys.secret_test | payments-redsys | Keys | secret | (secret) | Secret key (test) |
| payments.redsys.terminal | payments-redsys | General | text | "1" | Terminal |
| payments.redsys.title | payments-redsys | General | text | "" | Card payment title |
| payments.simulated.allow_live | payments-simulated | General | toggle | false | Allow it on a live shop |
| payments.simulated.description | payments-simulated | Texts | text |  | Description |
| payments.simulated.enabled | payments-simulated | General | toggle | true | Offer the simulated payment |
| payments.simulated.mode | payments-simulated | General | select | "ask" | What happens when the customer chooses it |
| payments.simulated.title | payments-simulated | Texts | text |  | Title |
| payments.transfer.bank | payments-transfer | Bank account | text |  | Bank |
| payments.transfer.days | payments-transfer | Bank account | number | 5 | Days to pay |
| payments.transfer.description | payments-transfer | Texts | text |  | Description |
| payments.transfer.holder | payments-transfer | Bank account | text |  | Account holder |
| payments.transfer.iban | payments-transfer | Bank account | text |  | IBAN |
| payments.transfer.note | payments-transfer | Bank account | textarea |  | Extra note |
| payments.transfer.title | payments-transfer | Texts | text |  | Title |
| returns.auto_approve_withdrawal | returns | Periods | toggle | false | Accept the withdrawals automatically |
| returns.days | returns | Periods | number | 30 | Days to ask for a return |
| returns.excluded_categories | returns | Reasons and exclusions | checks | [] | Categories excluded from the right of withdrawal |
| returns.exclusions_text | returns | Legal texts | textarea |  | Text about the products excluded by law |
| returns.reasons | returns | Reasons and exclusions | textarea |  | Reasons to return |
| returns.refund_shipping | returns | Periods | toggle | true | Refund the outgoing shipping when the whole order is withdrawn |
| returns.return_shipping_payer | returns | Shipping back | textarea |  | Who pays the shipping back |
| returns.shipping_instructions | returns | Shipping back | textarea |  | Shipping instructions |
| returns.transit_days | returns | Periods | number | 3 | Days of transit |
| returns.withdrawal_days | returns | Periods | number | 14 | Days of the right of withdrawal |
| returns.withdrawal_text | returns | Legal texts | textarea |  | Text of the withdrawal page |
| reviews.ask | reviews | Request by email | toggle | true | Ask buyers for a review by email |
| reviews.ask_days | reviews | Request by email | number | 7 | Days after the order is completed |
| reviews.auto_publish_verified | reviews | Reviews | toggle | false | Publish verified purchases without moderation |
| search.ai_expand | search | AI help | toggle | false | Ask the AI for more ways to search each product |
| search.ai_understand | search | AI help | toggle | true | Ask the AI to understand long phrases |
| search.default_synonyms | search | Understanding | toggle | true | Common shop synonyms |
| search.meaning_threshold | search | AI help | number | 0.6 | Similarity needed to offer a product by meaning |
| search.min_score | search | Ranking | number | 0.35 | Minimum score |
| search.synonyms | search | Understanding | textarea | "" | Words that mean the same |
| search.vectors | search | AI help | toggle | false | Rank also by meaning |
| search.w_attrs | search | Ranking | number | 2 | Attributes and key facts |
| search.w_brand | search | Ranking | number | 3 | Brand |
| search.w_cats | search | Ranking | number | 2.5 | Categories |
| search.w_desc | search | Ranking | number | 1 | Description |
| search.w_extra | search | Ranking | number | 0.8 | Extra search words |
| search.w_name | search | Ranking | number | 6 | Name |
| search.w_ref | search | Ranking | number | 4 | Reference and barcode |
| search.w_summary | search | Ranking | number | 1.5 | Summary |
| search.w_tags | search | Ranking | number | 2 | Tags |
| search_learning.default_history_days | search-learning | Suggested values | number | 7 | Suggested history period in days |
| search_learning.default_profiles | search-learning | Suggested values | number | 5 | Suggested number of simulated profiles |
| search_learning.default_tier | search-learning | Suggested values | select | "economy" | Suggested AI tier |
| semantic.feeds.google | semantic | Feeds | toggle | true | Google Merchant feed (google.xml) |
| semantic.feeds.google_category | semantic | Feeds | text | "" | Google product category |
| semantic.feeds.openai | semantic | Feeds | toggle | true | Shopping assistants feed (openai.json) |
| semantic.feeds.products | semantic | Feeds | toggle | true | General product feed (products.json) |
| semantic.llms_full_kb | semantic | Text versions | number | 500 | Size limit of llms-full.txt (KB) |
| semantic.logo | semantic | Organization | image | 0 | Logo for search engines |
| semantic.openai.enable_checkout | semantic | Feeds | toggle | false | Let assistants start the purchase |
| semantic.openai.enable_search | semantic | Feeds | toggle | true | Let assistants show the products in their answers |
| semantic.returns.days | semantic | Returns | number | 0 | Days to return a product |
| semantic.returns.method | semantic | Returns | select | "mail" | How the product comes back |
| semantic.returns.payer | semantic | Returns | select | "customer" | Who pays the return |
| semantic.social | semantic | Organization | textarea | "" | Social profiles |
| semantic.summary | semantic | Text versions | textarea | "" | Summary of the shop |
| seo.bots.assistant | seo | Robots | select | "allow" | AI assistants |
| seo.bots.search | seo | Robots | select | "allow" | Search engines |
| seo.bots.training | seo | Robots | select | "allow" | AI training |
| seo.description.blog | seo | Titles | text | "" | Description of the blog |
| seo.description.brand | seo | Titles | text | "" | Description of a brand |
| seo.description.category | seo | Titles | text | "" | Description of a category |
| seo.description.home | seo | Titles | text | "" | Description of the home page |
| seo.description.page | seo | Titles | text | "" | Description of a page |
| seo.description.post | seo | Titles | text | "" | Description of a post |
| seo.description.product | seo | Titles | text | "" | Description of a product |
| seo.max_indexed_page | seo | General | number | 0 | Last page of a listing that can be indexed |
| seo.noindex_site | seo | General | toggle | false | Hide the whole site from search engines |
| seo.robots_extra | seo | Robots | textarea | "" | Extra lines for robots.txt |
| seo.title.blog | seo | Titles | text | "{name} \| {shop}" | Title of the blog |
| seo.title.brand | seo | Titles | text | "{name} \| {shop}" | Title of a brand |
| seo.title.category | seo | Titles | text | "{name} \| {shop}" | Title of a category |
| seo.title.home | seo | Titles | text | "{shop}" | Title of the home page |
| seo.title.page | seo | Titles | text | "{name} \| {shop}" | Title of a page |
| seo.title.post | seo | Titles | text | "{name} \| {shop}" | Title of a post |
| seo.title.product | seo | Titles | text | "{name} \| {shop}" | Title of a product |
| seo.verify_bing | seo | Verification | text | "" | Bing Webmaster Tools code |
| seo.verify_google | seo | Verification | text | "" | Google Search Console code |
| shipping.default_country | shipping | General | select | "ES" | Default country |
| shipping.estimate_in_cart | shipping | General | toggle | true | Let customers estimate the shipping in the cart |
| subscriptions.enabled | subscriptions | General | toggle | false | Offer monthly subscriptions |
| subscriptions.paypal.client_id_live | subscriptions | Production keys | text |  | Production client ID |
| subscriptions.paypal.client_id_sandbox | subscriptions | Sandbox keys | text |  | Sandbox client ID |
| subscriptions.paypal.environment | subscriptions | General | select | "sandbox" | PayPal environment |
| subscriptions.paypal.secret_live | subscriptions | Production keys | secret | (secret) | Production secret |
| subscriptions.paypal.secret_sandbox | subscriptions | Sandbox keys | secret | (secret) | Sandbox secret |
| subscriptions.paypal.webhook_id_live | subscriptions | Production keys | text |  | Production webhook ID |
| subscriptions.paypal.webhook_id_sandbox | subscriptions | Sandbox keys | text |  | Sandbox webhook ID |
| subscriptions.provider | subscriptions | General | select | "simulated" | Provider |
| tax.base_country | tax | General | select | "ES" | Shop country |
| tax.base_postcode | tax | General | text |  | Shop postcode |
| tax.display_with_tax | tax | General | toggle | true | Show prices with tax in the shop |
| tax.eu_b2b_exempt | tax | General | toggle | false | No tax for businesses of other EU countries |
| tax.prices_include_tax | tax | General | toggle | true | Prices already include tax |
| tax.shipping_class | tax | General | select | "highest" | Tax of the shipping |
| tools.backup_auto | tools | Backups | toggle | true | Nightly backup |
| tools.backup_content | tools | Backups | select | "full" | What the nightly backup contains |
| tools.keep_backups | tools | Backups | number | 7 | Backups to keep |
| tools.keep_logs_days | tools | Log | number | 30 | Days to keep the log files |
| tools.maintenance | tools | Maintenance | toggle | false | Maintenance mode |
| tools.maintenance_text_es | tools | Maintenance | textarea | "" | Message for visitors |
| translations.publish_unreviewed | translations | General | toggle | false | Publish the AI translations without review |
| translations.sector | translations | General | text | "" | What the shop sells |
| webmcp.enabled | webmcp | General | toggle | true | Offer the shop tools to AI agents in the browser |
