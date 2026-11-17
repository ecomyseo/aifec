# Ganchos

Análisis estático del código (no ejecuta nada): `fire` dispara una acción, `apply` pasa un valor por los filtros y `render` pinta un hueco; `on`, `filter` y `slot` se enganchan a uno; `seal` lo sella con una capacidad. Solo salen los nombres literales; los que se montan con una variable van a la fila `dynamic`.

Generado con `php aifec docs:reference`. No lo edites a mano.

| Gancho | Tipo | Se dispara en | Se escucha en | Sellado con |
| --- | --- | --- | --- | --- |
| account.dashboard | slot | modules/customers/views/front/account.php:55 | modules/invoices/hooks.php:51<br>modules/returns/hooks.php:31<br>modules/subscriptions/hooks.php:19<br>plugins/gm-licencias/hooks.php:80 |  |
| admin.definitions | filter | core/src/Kernel/App.php:583 | modules/ai/hooks.php:31<br>modules/semantic/hooks.php:37<br>plugins/gm-licencias/hooks.php:74<br>plugins/gm-proximamente/hooks.php:60<br>plugins/hello-aifec/hooks.php:40 |  |
| admin.foot | slot | core/views/admin/layout.php:162 | modules/cms/hooks.php:63<br>modules/copilot/hooks.php:32<br>modules/import/hooks.php:48<br>modules/jobs/hooks.php:21<br>modules/media/hooks.php:28<br>modules/tools/hooks.php:36 |  |
| admin.head | slot | core/views/admin/layout.php:40 | modules/import/hooks.php:41<br>modules/media/hooks.php:25 |  |
| admin.order.side | slot | modules/orders/views/admin/order.php:334 | modules/erp/hooks.php:77<br>modules/invoices/hooks.php:56<br>modules/returns/hooks.php:36<br>plugins/gm-legal-producto/hooks.php:59 |  |
| admin.palette | filter | core/src/Admin/DashboardController.php:314 | modules/catalog/hooks.php:78<br>modules/catalog/hooks.php:79<br>modules/cms/hooks.php:111<br>modules/copilot/hooks.php:29<br>modules/orders/src/Admin/OrdersDashboard.php:39 |  |
| admin.php_version | filter | core/src/Admin/DashboardController.php:261 |  |  |
| admin.resource.deleted | action | core/src/Admin/ResourceController.php:591 | modules/catalog/hooks.php:52<br>modules/consent/hooks.php:64<br>modules/search/hooks.php:48<br>modules/semantic/hooks.php:59<br>modules/seo/hooks.php:43<br>modules/tax/hooks.php:34 |  |
| admin.resource.saved | action | core/src/Admin/ResourceController.php:244<br>modules/ai/src/Applier.php:65<br>modules/ai/src/Applier.php:87<br>modules/copilot/src/Actions/CatalogActions.php:1126 | modules/catalog/hooks.php:51<br>modules/consent/hooks.php:63<br>modules/search/hooks.php:47<br>modules/semantic/hooks.php:40<br>modules/semantic/hooks.php:58<br>modules/seo/hooks.php:42<br>modules/tax/hooks.php:33<br>plugins/gm-licencias/hooks.php:75<br>plugins/gm-proximamente/hooks.php:61<br>plugins/hello-aifec/hooks.php:61 |  |
| admin.status | filter | core/src/Admin/DashboardController.php:96 | modules/ai/hooks.php:34<br>modules/catalog/hooks.php:80<br>modules/cms/hooks.php:91<br>modules/import/hooks.php:57<br>modules/jobs/hooks.php:27<br>modules/mail/hooks.php:20<br>modules/orders/src/Admin/OrdersDashboard.php:38<br>modules/seo/hooks.php:46<br>modules/tools/hooks.php:29<br>modules/tools/hooks.php:35 |  |
| ai.apply | filter | modules/ai/src/Proposals.php:150 | modules/ai/hooks.php:26<br>modules/cms/hooks.php:71<br>modules/translations/hooks.php:31 |  |
| ai.approved | action | modules/ai/src/Proposals.php:172 | modules/translations/hooks.php:26 |  |
| app.boot | action | core/src/Kernel/App.php:700 |  |  |
| assistant.tools | filter | modules/assistant/src/Tools.php:58 | modules/returns/hooks.php:41 |  |
| body.end | slot | themes/aifec/templates/layout.php:45<br>themes/base/templates/layout.php:48 | modules/assistant/hooks.php:28<br>modules/catalog/hooks.php:88<br>modules/consent/hooks.php:48<br>modules/search/hooks.php:66<br>modules/webmcp/hooks.php:39 |  |
| body.start | slot | themes/aifec/templates/layout.php:31<br>themes/base/templates/layout.php:32 |  |  |
| cart.adjustments | filter | modules/orders/src/Cart.php:1288 | modules/orders/src/Admin/ManualOrderPrices.php:53 | pricing |
| cart.after | slot | modules/orders/views/front/cart.php:221 | modules/datalayer/hooks.php:47 |  |
| cart.before | slot | modules/orders/views/front/cart.php:62 |  |  |
| cart.changed | action | modules/orders/src/Cart.php:740 | plugins/gm-proximamente/hooks.php:35 |  |
| cart.lines.after | slot | modules/orders/views/front/cart.php:122 |  |  |
| cart.totals.after | slot | modules/orders/views/front/cart.php:198 |  |  |
| checkout.after | slot | modules/orders/views/front/checkout.php:178 | modules/datalayer/hooks.php:50 |  |
| checkout.before | slot | modules/orders/views/front/checkout.php:50 |  |  |
| checkout.review.after | slot | modules/orders/views/front/checkout.php:167 | plugins/gm-legal-producto/hooks.php:37 |  |
| cms.block.dynamic | filter | modules/cms/src/BlockCache.php:128 |  |  |
| cms.form.state | filter | modules/cms/src/Forms.php:261 | modules/cms/hooks.php:45 |  |
| consent.cookies | filter | modules/consent/src/ConsentService.php:159 | modules/datalayer/hooks.php:81 |  |
| content.saved | action | modules/cms/cli.php:44<br>modules/cms/src/AiApply.php:78<br>modules/cms/src/ContentAdmin.php:115<br>modules/cms/src/Legal.php:155<br>modules/cms/src/Legal.php:237<br>modules/cms/src/PageAdmin.php:195<br>modules/cms/src/PostAdmin.php:191<br>modules/cms/src/Revisions.php:151<br>modules/cms/src/TermAdmin.php:131<br>modules/cms/src/TermAdmin.php:153 | modules/search/hooks.php:56 |  |
| customer.anonymised | action | modules/customers/src/Customers.php:738 | modules/mail/hooks.php:22<br>modules/orders/hooks.php:63<br>modules/returns/hooks.php:46<br>modules/reviews/hooks.php:36 |  |
| customer.export | filter | modules/customers/src/Customers.php:701 | modules/orders/hooks.php:66<br>modules/returns/hooks.php:49<br>modules/reviews/hooks.php:39 |  |
| customer.login | action | modules/customers/src/Customers.php:161<br>modules/customers/src/Customers.php:627 | modules/datalayer/hooks.php:73<br>modules/orders/hooks.php:46 |  |
| customer.registered | action | modules/customers/src/Customers.php:249 | modules/datalayer/hooks.php:76<br>modules/erp/hooks.php:65 |  |
| doctor.checks | filter | modules/tools/src/Doctor.php:65 |  |  |
| feed.item | filter | modules/semantic/src/Feeds.php:246 |  |  |
| footer.after | slot | themes/aifec/templates/layout.php:41<br>themes/base/templates/layout.php:42 |  |  |
| footer.before | slot | themes/aifec/templates/layout.php:39<br>themes/base/templates/layout.php:40 | plugins/gm-aviso-ia/hooks.php:35 |  |
| footer.columns | slot | themes/aifec/templates/partials/footer.php:20<br>themes/base/templates/partials/footer.php:30<br>themes/cafe/templates/partials/footer.php:74<br>themes/gmartos/templates/partials/footer.php:21<br>themes/lookbook/templates/partials/footer.php:77<br>themes/metrica/templates/partials/footer.php:80 |  |  |
| header.actions | slot | themes/aifec/templates/partials/header.php:38<br>themes/atelier/templates/partials/header.php:99<br>themes/base/templates/partials/header.php:39<br>themes/cafe/templates/partials/header.php:85<br>themes/despensa/templates/partials/header.php:65<br>themes/gmartos/templates/partials/header.php:41<br>themes/lookbook/templates/partials/header.php:55<br>themes/metrica/templates/partials/header.php:62<br>themes/taller/templates/partials/header.php:43 |  |  |
| header.after | slot | themes/aifec/templates/layout.php:35<br>themes/base/templates/layout.php:36 |  |  |
| header.before | slot | themes/aifec/templates/layout.php:33<br>themes/base/templates/layout.php:34 |  |  |
| home.bottom | slot | themes/aifec/templates/home.php:48<br>themes/atelier/templates/home.php:104<br>themes/base/templates/home.php:68<br>themes/cafe/templates/home.php:103<br>themes/despensa/templates/home.php:103<br>themes/gmartos/templates/home.php:163<br>themes/lookbook/templates/home.php:85<br>themes/metrica/templates/home.php:143<br>themes/taller/templates/home.php:94 |  |  |
| home.sections | filter | core/src/Front/HomeController.php:51<br>modules/themes/src/Themes.php:384 | modules/catalog/hooks.php:73<br>modules/cms/hooks.php:44<br>themes/aifec/functions.php:455 |  |
| home.top | slot | themes/aifec/templates/home.php:28<br>themes/atelier/templates/home.php:84<br>themes/base/templates/home.php:54<br>themes/cafe/templates/home.php:89<br>themes/despensa/templates/home.php:77<br>themes/gmartos/templates/home.php:68<br>themes/lookbook/templates/home.php:61<br>themes/metrica/templates/home.php:129<br>themes/taller/templates/home.php:64 |  |  |
| import.finished | action | modules/import/src/Runner.php:172<br>modules/import/src/Runner.php:574<br>modules/import/src/Runner.php:933 |  |  |
| import.readers | filter | modules/import/src/Registry.php:50 | modules/import/hooks.php:22 |  |
| import.writers | filter | modules/import/src/Registry.php:91 |  |  |
| invoice.issued | action | modules/invoices/src/Invoices.php:284 | modules/erp/hooks.php:70 |  |
| listing.query | filter | modules/catalog/src/Listing.php:49 |  |  |
| listing.sorts | filter | modules/catalog/src/Listing.php:129 |  |  |
| llms.sections | filter | modules/semantic/src/Llms.php:126 | modules/api/hooks.php:25<br>modules/webmcp/hooks.php:51 |  |
| mail.templates | filter | modules/mail/src/Mailer.php:95 |  |  |
| media.usage | filter | modules/media/src/Library.php:332 | modules/cms/hooks.php:132 |  |
| menu.items | filter | core/helpers.php:227 | modules/catalog/hooks.php:74<br>modules/cms/hooks.php:29 |  |
| order.cancelled | action | modules/orders/src/Orders.php:597 | modules/coupons/hooks.php:21<br>plugins/gm-licencias/hooks.php:56 |  |
| order.download.allowed | unknown |  | plugins/gm-legal-producto/hooks.php:46 | orders |
| order.mail.blocks | filter | modules/orders/src/OrderMails.php:155 | plugins/gm-legal-producto/hooks.php:51<br>plugins/gm-licencias/hooks.php:27 |  |
| order.mail_attachments | filter | modules/orders/src/OrderMails.php:60 | modules/invoices/hooks.php:61 |  |
| order.paid | action | modules/orders/src/Orders.php:361 | modules/datalayer/hooks.php:65<br>modules/erp/hooks.php:40<br>modules/invoices/hooks.php:24<br>modules/orders/src/Admin/OrdersDashboard.php:43<br>plugins/gm-legal-producto/hooks.php:56<br>plugins/gm-licencias/hooks.php:33 |  |
| order.placed | action | modules/orders/src/Orders.php:127<br>modules/orders/src/RecurringOrders.php:177 | modules/assistant/hooks.php:46<br>modules/datalayer/hooks.php:58<br>modules/erp/hooks.php:35<br>plugins/gm-legal-producto/hooks.php:40 |  |
| order.refunded | action | modules/orders/src/Orders.php:731 | modules/datalayer/hooks.php:68<br>modules/erp/hooks.php:50<br>modules/invoices/hooks.php:36<br>modules/orders/src/Admin/OrdersDashboard.php:44<br>plugins/gm-licencias/hooks.php:45 |  |
| order.status | action | modules/orders/src/Orders.php:398<br>modules/orders/src/Orders.php:438<br>modules/orders/src/Orders.php:519 | modules/erp/hooks.php:45<br>modules/reviews/hooks.php:29 |  |
| order.thanks | slot | modules/orders/views/front/order.php:67 | modules/datalayer/hooks.php:53<br>modules/invoices/hooks.php:48<br>modules/returns/hooks.php:28<br>plugins/gm-licencias/hooks.php:88 |  |
| page.meta | filter | core/src/Front/Page.php:72 | modules/semantic/hooks.php:47<br>modules/seo/hooks.php:23<br>modules/themes/hooks.php:64<br>plugins/gm-aviso-ia/hooks.php:28 |  |
| product.availability | filter | modules/catalog/src/Stock.php:286 | plugins/gm-proximamente/hooks.php:24 | pricing |
| product.badges | filter | modules/catalog/src/ProductData.php:468 | plugins/gm-proximamente/hooks.php:29 |  |
| product.bottom | slot | modules/catalog/views/front/product.php:194<br>themes/atelier/templates/modules/catalog/front/product.php:209<br>themes/cafe/templates/modules/catalog/front/product.php:260<br>themes/despensa/templates/modules/catalog/front/product.php:222<br>themes/gmartos/templates/modules/catalog/front/product.php:216<br>themes/lookbook/templates/modules/catalog/front/product.php:198<br>themes/metrica/templates/modules/catalog/front/product.php:244<br>themes/taller/templates/modules/catalog/front/product.php:206 | modules/ai/hooks.php:37<br>modules/datalayer/hooks.php:44<br>modules/reviews/hooks.php:24<br>plugins/gm-aviso-ia/hooks.php:22<br>plugins/gm-legal-producto/hooks.php:34 |  |
| product.buy.after | slot | modules/catalog/views/front/product.php:136<br>themes/atelier/templates/modules/catalog/front/product.php:148<br>themes/cafe/templates/modules/catalog/front/product.php:208<br>themes/despensa/templates/modules/catalog/front/product.php:162<br>themes/gmartos/templates/modules/catalog/front/product.php:132<br>themes/lookbook/templates/modules/catalog/front/product.php:151<br>themes/metrica/templates/modules/catalog/front/product.php:187<br>themes/taller/templates/modules/catalog/front/product.php:138 | plugins/gm-proximamente/hooks.php:48 |  |
| product.data | filter | modules/catalog/src/ProductData.php:272 |  |  |
| product.extra | filter | modules/catalog/src/ProductData.php:269 | plugins/gm-licencias/hooks.php:63<br>plugins/hello-aifec/hooks.php:19 |  |
| product.gallery.after | slot | modules/catalog/views/front/product.php:93<br>themes/atelier/templates/modules/catalog/front/product.php:93<br>themes/cafe/templates/modules/catalog/front/product.php:90<br>themes/despensa/templates/modules/catalog/front/product.php:107<br>themes/gmartos/templates/modules/catalog/front/product.php:98<br>themes/lookbook/templates/modules/catalog/front/product.php:97<br>themes/metrica/templates/modules/catalog/front/product.php:120<br>themes/taller/templates/modules/catalog/front/product.php:89 |  |  |
| product.gallery.before | slot | modules/catalog/views/front/product.php:74<br>themes/atelier/templates/modules/catalog/front/product.php:82<br>themes/despensa/templates/modules/catalog/front/product.php:90<br>themes/gmartos/templates/modules/catalog/front/product.php:79<br>themes/lookbook/templates/modules/catalog/front/product.php:90<br>themes/metrica/templates/modules/catalog/front/product.php:116<br>themes/taller/templates/modules/catalog/front/product.php:70 | modules/catalog/hooks.php:62 |  |
| product.price | filter | modules/catalog/src/Pricing.php:254 |  | pricing |
| product.price.after | slot | modules/catalog/views/front/product.php:111<br>themes/atelier/templates/modules/catalog/front/product.php:111<br>themes/cafe/templates/modules/catalog/front/product.php:179<br>themes/despensa/templates/modules/catalog/front/product.php:135<br>themes/gmartos/templates/modules/catalog/front/product.php:104<br>themes/lookbook/templates/modules/catalog/front/product.php:119<br>themes/metrica/templates/modules/catalog/front/product.php:161<br>themes/taller/templates/modules/catalog/front/product.php:110 | plugins/gm-proximamente/hooks.php:40 |  |
| product.size_guide | slot | themes/atelier/templates/modules/catalog/front/product.php:68 |  |  |
| product.summary.after | slot | modules/catalog/views/front/product.php:147<br>themes/atelier/templates/modules/catalog/front/product.php:195<br>themes/cafe/templates/modules/catalog/front/product.php:209<br>themes/despensa/templates/modules/catalog/front/product.php:166<br>themes/gmartos/templates/modules/catalog/front/product.php:152<br>themes/lookbook/templates/modules/catalog/front/product.php:152<br>themes/metrica/templates/modules/catalog/front/product.php:195<br>themes/taller/templates/modules/catalog/front/product.php:149 | plugins/gm-aviso-ia/hooks.php:19<br>plugins/gm-legal-producto/hooks.php:33<br>plugins/hello-aifec/hooks.php:30 |  |
| product.summary.before | slot | modules/catalog/views/front/product.php:96<br>themes/atelier/templates/modules/catalog/front/product.php:96<br>themes/cafe/templates/modules/catalog/front/product.php:140<br>themes/despensa/templates/modules/catalog/front/product.php:110<br>themes/gmartos/templates/modules/catalog/front/product.php:101<br>themes/lookbook/templates/modules/catalog/front/product.php:100<br>themes/metrica/templates/modules/catalog/front/product.php:143<br>themes/taller/templates/modules/catalog/front/product.php:92 |  |  |
| product.tabs | slot | modules/catalog/views/front/product.php:158<br>themes/atelier/templates/modules/catalog/front/product.php:166<br>themes/cafe/templates/modules/catalog/front/product.php:238<br>themes/despensa/templates/modules/catalog/front/product.php:187<br>themes/gmartos/templates/modules/catalog/front/product.php:205<br>themes/lookbook/templates/modules/catalog/front/product.php:176<br>themes/metrica/templates/modules/catalog/front/product.php:206<br>themes/taller/templates/modules/catalog/front/product.php:184 |  |  |
| request.end | action | core/src/Kernel/App.php:1216 | modules/themes/hooks.php:43<br>themes/base/functions.php:61<br>themes/cafe/functions.php:431<br>themes/despensa/functions.php:335 |  |
| request.not_found | filter | core/src/Kernel/App.php:1043 | modules/cms/hooks.php:68 |  |
| request.start | filter | core/src/Kernel/App.php:978 | modules/api/hooks.php:22<br>modules/docs/hooks.php:22<br>modules/semantic/hooks.php:34<br>modules/semantic/hooks.php:50<br>modules/themes/hooks.php:31<br>modules/tools/hooks.php:33<br>plugins/gm-proximamente/hooks.php:34 |  |
| return.requested | action | modules/returns/src/Returns.php:230 |  |  |
| return.status | action | modules/returns/src/Returns.php:529 |  |  |
| review.published | action | modules/reviews/src/Reviews.php:340 |  |  |
| search.plan.text | filter | modules/search/src/Ask.php:121 | modules/search-learning/hooks.php:19 |  |
| search.result.ids | filter | modules/search/src/Search.php:607 | modules/search-learning/hooks.php:22 |  |
| settings.saved | action | core/cli.php:144<br>core/src/Admin/SettingsController.php:129<br>modules/copilot/src/Actions/SettingActions.php:334<br>modules/orders/src/Admin/PaymentsAdmin.php:125 | modules/assistant/hooks.php:39<br>modules/catalog/hooks.php:81<br>modules/catalog/hooks.php:112<br>modules/cms/hooks.php:74<br>modules/consent/hooks.php:53<br>modules/datalayer/hooks.php:86<br>modules/payments-transfer/hooks.php:21<br>modules/semantic/hooks.php:65<br>modules/seo/hooks.php:32<br>modules/tools/hooks.php:39<br>modules/webmcp/hooks.php:67<br>plugins/gm-aviso-ia/hooks.php:43<br>plugins/gm-legal-producto/hooks.php:64 |  |
| shipping.options | filter | modules/shipping/src/Shipping.php:167 |  | pricing |
| stock.changed | action | modules/catalog/src/Stock.php:198 | modules/erp/hooks.php:56<br>modules/search/hooks.php:51<br>modules/semantic/hooks.php:60 |  |
| tax.rate | filter | modules/tax/src/TaxService.php:113 |  | pricing |
| translations.scopes | filter | modules/translations/src/Scopes.php:56 |  |  |
| webmcp.form | slot | modules/catalog/views/front/product.php:117<br>modules/orders/views/front/cart.php:103<br>modules/orders/views/front/cart.php:126<br>modules/orders/views/front/cart.php:153<br>themes/base/templates/partials/header.php:30<br>themes/cafe/templates/modules/catalog/front/product.php:181<br>themes/lookbook/templates/modules/catalog/front/product.php:124<br>themes/lookbook/templates/partials/header.php:38<br>themes/metrica/templates/modules/catalog/front/product.php:164<br>themes/metrica/templates/partials/drawers.php:57 | modules/webmcp/hooks.php:25 |  |
| webmcp.param | slot | modules/catalog/views/front/partials/options.php:50<br>modules/catalog/views/front/product.php:118<br>modules/catalog/views/front/product.php:119<br>modules/orders/views/front/cart.php:104<br>modules/orders/views/front/cart.php:128<br>modules/orders/views/front/cart.php:154<br>modules/orders/views/front/cart.php:158<br>modules/orders/views/front/cart.php:166<br>themes/base/templates/partials/header.php:32<br>themes/base/templates/partials/qty.php:30<br>themes/cafe/templates/modules/catalog/front/product.php:182<br>themes/cafe/templates/modules/catalog/front/product.php:183<br>themes/lookbook/templates/modules/catalog/front/product.php:125<br>themes/lookbook/templates/modules/catalog/front/product.php:126<br>themes/lookbook/templates/partials/header.php:40<br>themes/metrica/templates/modules/catalog/front/product.php:165<br>themes/metrica/templates/modules/catalog/front/product.php:166<br>themes/metrica/templates/partials/drawers.php:59 | modules/webmcp/hooks.php:31 |  |
| dynamic | dynamic | core/helpers.php:104<br>modules/copilot/src/Actions.php:65<br>modules/orders/src/Downloads.php:173 | modules/copilot/hooks.php:25 |  |
