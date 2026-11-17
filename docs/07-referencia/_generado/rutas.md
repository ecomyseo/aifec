# Rutas

Las rutas fijas registradas en el enrutador, por área (`admin`, `api` o `front`). Las direcciones amigables de productos, categorías y páginas no están aquí: se resuelven después en la tabla de direcciones. A `GET` le acompaña siempre `HEAD`.

Generado con `php aifec docs:reference`. No lo edites a mano.

| Área | Métodos | Ruta | Nombre | Intermediarios |
| --- | --- | --- | --- | --- |
| admin | GET | /admin | admin.home | admin |
| admin | GET | /admin/ai/batches | admin.ai.batches | admin |
| admin | POST | /admin/ai/batches/estimate |  | admin |
| admin | POST | /admin/ai/batches/start |  | admin |
| admin | POST | /admin/ai/batches/{id:\\d+}/cancel |  | admin |
| admin | POST | /admin/ai/batches/{id:\\d+}/pause |  | admin |
| admin | POST | /admin/ai/batches/{id:\\d+}/resume |  | admin |
| admin | GET | /admin/ai/connection | admin.ai.connection | admin |
| admin | POST | /admin/ai/connection |  | admin |
| admin | GET | /admin/ai/proposals | admin.ai.proposals | admin |
| admin | POST | /admin/ai/proposals/bulk |  | admin |
| admin | GET | /admin/ai/proposals/{id:\\d+} | admin.ai.proposal | admin |
| admin | POST | /admin/ai/proposals/{id:\\d+}/approve |  | admin |
| admin | POST | /admin/ai/proposals/{id:\\d+}/reject |  | admin |
| admin | POST | /admin/ai/proposals/{id:\\d+}/save |  | admin |
| admin | POST | /admin/ai/propose |  | admin |
| admin | GET | /admin/ai/usage | admin.ai.usage | admin |
| admin | GET | /admin/assistant | admin.assistant | admin |
| admin | GET | /admin/assistant/unanswered | admin.assistant.unanswered | admin |
| admin | GET | /admin/assistant/{id:\\d+} | admin.assistant.show | admin |
| admin | GET | /admin/catalog/attributes/{id:\\d+}/values | admin.catalog.attribute_values | admin |
| admin | POST | /admin/catalog/attributes/{id:\\d+}/values |  | admin |
| admin | GET | /admin/catalog/categories/pick | admin.catalog.category_pick | admin |
| admin | POST | /admin/catalog/categories/quick | admin.catalog.category_quick | admin |
| admin | POST | /admin/catalog/products/quickfill | admin.catalog.quickfill | admin |
| admin | POST | /admin/catalog/products/{id:\\d+}/duplicate |  | admin |
| admin | GET | /admin/catalog/products/{id:\\d+}/variants | admin.catalog.variants | admin |
| admin | POST | /admin/catalog/products/{id:\\d+}/variants |  | admin |
| admin | GET | /admin/catalog/stock | admin.catalog.stock | admin |
| admin | POST | /admin/catalog/stock |  | admin |
| admin | POST | /admin/cms/blocks/import | admin.cms.blocks.import | admin |
| admin | POST | /admin/cms/blocks/render | admin.cms.blocks.render | admin |
| admin | GET | /admin/cms/entries/export | admin.cms.entries.export | admin |
| admin | GET | /admin/cms/legal | admin.cms.legal | admin |
| admin | POST | /admin/cms/legal |  | admin |
| admin | GET | /admin/cms/menus | admin.cms.menus | admin |
| admin | GET | /admin/cms/menus/search | admin.cms.menus.search | admin |
| admin | GET | /admin/cms/menus/{code:[a-z][a-z0-9_-]*} |  | admin |
| admin | POST | /admin/cms/menus/{code:[a-z][a-z0-9_-]*} |  | admin |
| admin | GET | /admin/cms/not-found | admin.cms.not_found | admin |
| admin | POST | /admin/cms/not-found/clear |  | admin |
| admin | POST | /admin/cms/not-found/redirect |  | admin |
| admin | GET | /admin/cms/preview/{type:page\|post}/{id:\\d+} | admin.cms.preview | admin |
| admin | POST | /admin/cms/revisions/{id:\\d+}/restore | admin.cms.restore | admin |
| admin | GET | /admin/consent | admin.consent | admin |
| admin | GET | /admin/consent/export.csv | admin.consent.export | admin |
| admin | GET | /admin/copilot | admin.copilot | admin |
| admin | POST | /admin/copilot/ask | admin.copilot.ask | admin |
| admin | GET | /admin/copilot/{id:\\d+} | admin.copilot.show | admin |
| admin | POST | /admin/copilot/{id:\\d+}/apply | admin.copilot.apply | admin |
| admin | POST | /admin/copilot/{id:\\d+}/choose | admin.copilot.choose | admin |
| admin | POST | /admin/copilot/{id:\\d+}/complete | admin.copilot.complete | admin |
| admin | POST | /admin/copilot/{id:\\d+}/discard | admin.copilot.discard | admin |
| admin | POST | /admin/copilot/{id:\\d+}/undo | admin.copilot.undo | admin |
| admin | GET | /admin/customers/export.csv | admin.customers.export | admin |
| admin | GET | /admin/customers/{id:\\d+}/addresses | admin.customers.addresses | admin |
| admin | POST | /admin/customers/{id:\\d+}/addresses/save | admin.customers.addresses.save | admin |
| admin | POST | /admin/customers/{id:\\d+}/addresses/{address:\\d+}/delete | admin.customers.addresses.delete | admin |
| admin | POST | /admin/customers/{id:\\d+}/anonymise | admin.customers.anonymise | admin |
| admin | GET | /admin/customers/{id:\\d+}/data.json | admin.customers.data | admin |
| admin | GET | /admin/datalayer/events | admin.datalayer.events | admin |
| admin | GET | /admin/erp | admin.erp | admin |
| admin | GET | /admin/erp/connections/new |  | admin |
| admin | POST | /admin/erp/connections/new |  | admin |
| admin | GET | /admin/erp/connections/{id:\\d+} |  | admin |
| admin | POST | /admin/erp/connections/{id:\\d+}/revoke |  | admin |
| admin | POST | /admin/erp/connections/{id:\\d+}/secret |  | admin |
| admin | POST | /admin/erp/connections/{id:\\d+}/toggle |  | admin |
| admin | POST | /admin/erp/connections/{id:\\d+}/webhook |  | admin |
| admin | GET | /admin/erp/deliveries |  | admin |
| admin | POST | /admin/erp/deliveries/{id:\\d+}/resend |  | admin |
| admin | POST | /admin/erp/exports/{id:\\d+}/interval |  | admin |
| admin | GET | /admin/erp/runs |  | admin |
| admin | GET | /admin/erp/runs/{id:\\d+} |  | admin |
| admin | GET | /admin/erp/runs/{id:\\d+}/download |  | admin |
| admin | POST | /admin/erp/webhooks/{id:\\d+}/reactivate |  | admin |
| admin | POST | /admin/erp/webhooks/{id:\\d+}/test |  | admin |
| admin | GET | /admin/erp/{dir:exports\|imports} |  | admin |
| admin | GET | /admin/erp/{dir:exports\|imports}/new |  | admin |
| admin | POST | /admin/erp/{dir:exports\|imports}/save |  | admin |
| admin | GET | /admin/erp/{dir:exports\|imports}/{id:\\d+} |  | admin |
| admin | POST | /admin/erp/{dir:exports\|imports}/{id:\\d+}/delete |  | admin |
| admin | POST | /admin/erp/{dir:exports\|imports}/{id:\\d+}/run |  | admin |
| admin | GET | /admin/forgot |  |  |
| admin | POST | /admin/forgot |  |  |
| admin | POST | /admin/gm-licencias/{id:\\d+}/domain/{domainId:\\d+}/remove | admin.gm_licenses.domain | admin |
| admin | POST | /admin/gm-licencias/{id:\\d+}/extend | admin.gm_licenses.extend | admin |
| admin | POST | /admin/gm-licencias/{id:\\d+}/reactivate | admin.gm_licenses.reactivate | admin |
| admin | POST | /admin/gm-licencias/{id:\\d+}/reissue | admin.gm_licenses.reissue | admin |
| admin | POST | /admin/gm-licencias/{id:\\d+}/revoke | admin.gm_licenses.revoke | admin |
| admin | GET | /admin/import | admin.import | admin |
| admin | GET | /admin/import/new | admin.import.new | admin |
| admin | POST | /admin/import/new |  | admin |
| admin | POST | /admin/import/{id:\\d+}/cancel |  | admin |
| admin | GET | /admin/import/{id:\\d+}/confirm |  | admin |
| admin | POST | /admin/import/{id:\\d+}/confirm |  | admin |
| admin | GET | /admin/import/{id:\\d+}/connection |  | admin |
| admin | POST | /admin/import/{id:\\d+}/connection |  | admin |
| admin | GET | /admin/import/{id:\\d+}/equivalences |  | admin |
| admin | POST | /admin/import/{id:\\d+}/equivalences |  | admin |
| admin | POST | /admin/import/{id:\\d+}/pause |  | admin |
| admin | POST | /admin/import/{id:\\d+}/poll |  | admin |
| admin | GET | /admin/import/{id:\\d+}/print |  | admin |
| admin | GET | /admin/import/{id:\\d+}/progress |  | admin |
| admin | GET | /admin/import/{id:\\d+}/report |  | admin |
| admin | GET | /admin/import/{id:\\d+}/report.csv |  | admin |
| admin | POST | /admin/import/{id:\\d+}/resume |  | admin |
| admin | POST | /admin/import/{id:\\d+}/simulate |  | admin |
| admin | GET | /admin/import/{id:\\d+}/simulation |  | admin |
| admin | GET | /admin/import/{id:\\d+}/types |  | admin |
| admin | POST | /admin/import/{id:\\d+}/types |  | admin |
| admin | GET | /admin/invoices | admin.invoices | admin |
| admin | GET | /admin/invoices/export.csv | admin.invoices.export | admin |
| admin | POST | /admin/invoices/issue |  | admin |
| admin | GET | /admin/invoices/{id:\\d+}/pdf | admin.invoices.pdf | admin |
| admin | POST | /admin/jobs/retry/{id:\\d+} |  | admin |
| admin | POST | /admin/jobs/run/{task:[A-Za-z0-9._-]+} |  | admin |
| admin | GET | /admin/jobs/schedule | admin.jobs.schedule | admin |
| admin | POST | /admin/jobs/tick | admin.jobs.tick | admin |
| admin | GET | /admin/login | admin.login |  |
| admin | POST | /admin/login |  |  |
| admin | GET | /admin/login/code |  |  |
| admin | POST | /admin/login/code |  |  |
| admin | POST | /admin/logout | admin.logout | admin |
| admin | GET | /admin/mail/templates | admin.mail.templates | admin |
| admin | GET | /admin/mail/templates/{key:[A-Za-z0-9_.-]+} | admin.mail.preview | admin |
| admin | POST | /admin/mail/templates/{key:[A-Za-z0-9_.-]+}/ai/propose | admin.mail.ai.propose | admin |
| admin | POST | /admin/mail/templates/{key:[A-Za-z0-9_.-]+}/ai/translate | admin.mail.ai.translate | admin |
| admin | POST | /admin/mail/templates/{key:[A-Za-z0-9_.-]+}/ai/translate/save | admin.mail.ai.translate_save | admin |
| admin | GET | /admin/mail/templates/{key:[A-Za-z0-9_.-]+}/edit | admin.mail.edit | admin |
| admin | POST | /admin/mail/templates/{key:[A-Za-z0-9_.-]+}/edit | admin.mail.save | admin |
| admin | POST | /admin/mail/test | admin.mail.test | admin |
| admin | POST | /admin/mail/{id:\\d+}/release | admin.mail.release | admin |
| admin | POST | /admin/mail/{id:\\d+}/resend | admin.mail.resend | admin |
| admin | GET | /admin/media | admin.media | admin |
| admin | GET | /admin/media/picker | admin.media.picker | admin |
| admin | POST | /admin/media/upload | admin.media.upload | admin |
| admin | GET | /admin/media/{id:\\d+} |  | admin |
| admin | POST | /admin/media/{id:\\d+} |  | admin |
| admin | POST | /admin/media/{id:\\d+}/delete |  | admin |
| admin | POST | /admin/media/{id:\\d+}/regenerate |  | admin |
| admin | GET | /admin/orders | admin.orders | admin |
| admin | GET | /admin/orders/carts | admin.orders.carts | admin |
| admin | GET | /admin/orders/export.csv | admin.orders.export | admin |
| admin | GET | /admin/orders/new | admin.orders.new | admin |
| admin | GET | /admin/orders/new/find/{what:customers\|products} |  | admin |
| admin | POST | /admin/orders/new/place |  | admin |
| admin | POST | /admin/orders/new/quote | admin.orders.new.quote |  |
| admin | POST | /admin/orders/new/{step:[a-z-]+} |  | admin |
| admin | GET | /admin/orders/picking | admin.orders.picking | admin |
| admin | GET | /admin/orders/{id:\\d+} | admin.orders.show | admin |
| admin | POST | /admin/orders/{id:\\d+}/cancel |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/note |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/paid |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/refund |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/repeat |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/resend |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/status |  | admin |
| admin | POST | /admin/orders/{id:\\d+}/tracking |  | admin |
| admin | GET | /admin/palette.json |  | admin |
| admin | GET | /admin/payments | admin.payments | admin |
| admin | POST | /admin/payments/enable/{id:[a-z0-9-]+} |  | admin |
| admin | POST | /admin/payments/order |  | admin |
| admin | GET | /admin/plugins | admin.plugins | admin |
| admin | GET | /admin/plugins/new | admin.plugins.new | admin |
| admin | POST | /admin/plugins/new |  | admin |
| admin | POST | /admin/plugins/upload |  | admin |
| admin | POST | /admin/plugins/{id:[a-z0-9-]+}/disable |  | admin |
| admin | POST | /admin/plugins/{id:[a-z0-9-]+}/enable |  | admin |
| admin | POST | /admin/plugins/{id:[a-z0-9-]+}/grant |  | admin |
| admin | GET | /admin/plugins/{id:[a-z0-9-]+}/report |  | admin |
| admin | POST | /admin/plugins/{id:[a-z0-9-]+}/uninstall |  | admin |
| admin | POST | /admin/plugins/{id:[a-z0-9-]+}/validate |  | admin |
| admin | GET | /admin/profile | admin.profile | admin |
| admin | POST | /admin/profile/data |  | admin |
| admin | POST | /admin/profile/password |  | admin |
| admin | POST | /admin/profile/sessions/close |  | admin |
| admin | POST | /admin/profile/two-step/cancel |  | admin |
| admin | POST | /admin/profile/two-step/codes |  | admin |
| admin | POST | /admin/profile/two-step/confirm |  | admin |
| admin | POST | /admin/profile/two-step/disable |  | admin |
| admin | POST | /admin/profile/two-step/start |  | admin |
| admin | GET | /admin/r/carriers |  | admin |
| admin | GET | /admin/r/categories | admin.catalog.categories | admin |
| admin | GET | /admin/r/customers/export.csv |  | admin |
| admin | GET | /admin/r/shipping_zones |  | admin |
| admin | GET | /admin/r/{resource:[a-z0-9_-]+} | admin.resource | admin |
| admin | POST | /admin/r/{resource:[a-z0-9_-]+}/bulk |  | admin |
| admin | GET | /admin/r/{resource:[a-z0-9_-]+}/export\\.csv |  | admin |
| admin | GET | /admin/r/{resource:[a-z0-9_-]+}/new |  | admin |
| admin | POST | /admin/r/{resource:[a-z0-9_-]+}/save |  | admin |
| admin | GET | /admin/r/{resource:[a-z0-9_-]+}/search\\.json |  | admin |
| admin | GET | /admin/r/{resource:[a-z0-9_-]+}/{id:\\d+} |  | admin |
| admin | POST | /admin/r/{resource:[a-z0-9_-]+}/{id:\\d+}/delete |  | admin |
| admin | POST | /admin/r/{resource:[a-z0-9_-]+}/{id:\\d+}/save |  | admin |
| admin | GET | /admin/reports | admin.reports | admin |
| admin | GET | /admin/reports/export.csv | admin.reports.export | admin |
| admin | GET | /admin/reset/{token} |  |  |
| admin | POST | /admin/reset/{token} |  |  |
| admin | GET | /admin/returns | admin.returns | admin |
| admin | GET | /admin/returns/{id:\\d+} | admin.returns.show | admin |
| admin | POST | /admin/returns/{id:\\d+}/approve |  | admin |
| admin | POST | /admin/returns/{id:\\d+}/cancel |  | admin |
| admin | POST | /admin/returns/{id:\\d+}/note |  | admin |
| admin | GET | /admin/returns/{id:\\d+}/photo/{n:\\d+} | admin.returns.photo | admin |
| admin | POST | /admin/returns/{id:\\d+}/receive |  | admin |
| admin | POST | /admin/returns/{id:\\d+}/refund |  | admin |
| admin | POST | /admin/returns/{id:\\d+}/reject |  | admin |
| admin | GET | /admin/reviews | admin.reviews | admin |
| admin | GET | /admin/reviews/{id:\\d+} | admin.reviews.show | admin |
| admin | POST | /admin/reviews/{id:\\d+}/publish |  | admin |
| admin | POST | /admin/reviews/{id:\\d+}/reject |  | admin |
| admin | POST | /admin/reviews/{id:\\d+}/reply |  | admin |
| admin | GET | /admin/search | admin.search | admin |
| admin | GET | /admin/search/learning | admin.search_learning | admin |
| admin | POST | /admin/search/learning/runs | admin.search_learning.create | admin |
| admin | GET | /admin/search/learning/runs/{id:\\d+} | admin.search_learning.show | admin |
| admin | GET | /admin/search/learning/runs/{id:\\d+}/report/{kind:experience\|improvements} | admin.search_learning.report | admin |
| admin | POST | /admin/search/learning/runs/{id:\\d+}/{action:resume\|cancel\|validate\|apply\|revert} | admin.search_learning.action | admin |
| admin | POST | /admin/search/reindex |  | admin |
| admin | GET | /admin/semantic/audit | admin.semantic.audit | admin |
| admin | POST | /admin/semantic/audit/run | admin.semantic.audit.run | admin |
| admin | GET | /admin/semantic/bots | admin.semantic.bots | admin |
| admin | GET | /admin/seo/audit | admin.seo.audit | admin |
| admin | GET | /admin/settings | admin.settings | admin |
| admin | GET | /admin/settings/shipping |  | admin |
| admin | GET | /admin/settings/{module:[a-z0-9-]+} |  | admin |
| admin | POST | /admin/settings/{module:[a-z0-9-]+} |  | admin |
| admin | GET | /admin/shipping | admin.shipping | admin |
| admin | GET | /admin/shipping/carriers | admin.shipping.carriers | admin |
| admin | POST | /admin/shipping/rates/{id:\\d+} | admin.shipping.rate | admin |
| admin | GET | /admin/shipping/settings | admin.shipping.settings | admin |
| admin | GET | /admin/shipping/test | admin.shipping.test | admin |
| admin | GET | /admin/shipping/zones | admin.shipping.zones | admin |
| admin | GET | /admin/sites | admin.sites | admin |
| admin | POST | /admin/sites/create |  | admin |
| admin | POST | /admin/sites/delete/{id:[a-z0-9-]+} |  | admin |
| admin | GET | /admin/sites/open/{id:[a-z0-9-]+} |  | admin |
| admin | POST | /admin/sites/reset/{id:[a-z0-9-]+} |  | admin |
| admin | POST | /admin/sites/settings |  | admin |
| admin | GET | /admin/subscriptions | admin.subscriptions | admin |
| admin | GET | /admin/subscriptions/{id:\\d+} | admin.subscriptions.show | admin |
| admin | POST | /admin/subscriptions/{id:\\d+}/{action:cancel\|suspend\|activate\|paid\|failed} | admin.subscriptions.action | admin |
| admin | POST | /admin/team/{id:\\d+}/two-step/remove |  | admin |
| admin | GET | /admin/themes | admin.themes | admin |
| admin | GET | /admin/themes/brand | admin.themes.brand | admin |
| admin | POST | /admin/themes/brand |  | admin |
| admin | GET | /admin/themes/customise/{slug:[a-z0-9-]+} |  | admin |
| admin | POST | /admin/themes/customise/{slug:[a-z0-9-]+} |  | admin |
| admin | GET | /admin/themes/home | admin.themes.home | admin |
| admin | POST | /admin/themes/home |  | admin |
| admin | GET | /admin/themes/new | admin.themes.new | admin |
| admin | POST | /admin/themes/new |  | admin |
| admin | GET | /admin/themes/preview/{slug:[a-z0-9-]+}\\.png |  | admin |
| admin | POST | /admin/themes/{slug:[a-z0-9-]+}/activate |  | admin |
| admin | GET | /admin/themes/{slug:[a-z0-9-]+}/report |  | admin |
| admin | GET | /admin/tools/backups | admin.tools.backups | admin |
| admin | POST | /admin/tools/backups/create |  | admin |
| admin | POST | /admin/tools/backups/delete/{name:[A-Za-z0-9._-]+} |  | admin |
| admin | GET | /admin/tools/backups/download/{name:[A-Za-z0-9._-]+} |  | admin |
| admin | GET | /admin/tools/backups/restore/{name:[A-Za-z0-9._-]+} |  | admin |
| admin | POST | /admin/tools/backups/restore/{name:[A-Za-z0-9._-]+} |  | admin |
| admin | GET | /admin/tools/backups/run/{token:[a-f0-9]{12}} |  | admin |
| admin | POST | /admin/tools/backups/run/{token:[a-f0-9]{12}} |  | admin |
| admin | POST | /admin/tools/backups/run/{token:[a-f0-9]{12}}/cancel |  | admin |
| admin | POST | /admin/tools/backups/verify/{name:[A-Za-z0-9._-]+} |  | admin |
| admin | GET | /admin/tools/cache | admin.tools.cache | admin |
| admin | POST | /admin/tools/cache/clear-data |  | admin |
| admin | POST | /admin/tools/cache/clear-pages |  | admin |
| admin | POST | /admin/tools/cache/maintenance |  | admin |
| admin | POST | /admin/tools/cache/publish-assets |  | admin |
| admin | GET | /admin/tools/csv | admin.tools.csv | admin |
| admin | GET | /admin/tools/csv/export |  | admin |
| admin | POST | /admin/tools/csv/upload |  | admin |
| admin | GET | /admin/tools/csv/{token:[a-f0-9]{24}} |  | admin |
| admin | POST | /admin/tools/csv/{token:[a-f0-9]{24}}/cancel |  | admin |
| admin | GET | /admin/tools/csv/{token:[a-f0-9]{24}}/report.csv |  | admin |
| admin | POST | /admin/tools/csv/{token:[a-f0-9]{24}}/run |  | admin |
| admin | GET | /admin/tools/doctor | admin.tools.doctor | admin |
| admin | GET | /admin/tools/info | admin.tools.info | admin |
| admin | GET | /admin/tools/logs | admin.tools.logs | admin |
| admin | GET | /admin/tools/logs/download |  | admin |
| admin | GET | /admin/translations | admin.translations | admin |
| admin | POST | /admin/translations/glossary | admin.translations.glossary | admin |
| admin | POST | /admin/translations/jobs/{id:\\d+}/{action:pause\|resume\|cancel} | admin.translations.job | admin |
| admin | GET | /admin/translations/{lang:[a-z]{2}}/export.csv | admin.translations.export | admin |
| admin | POST | /admin/translations/{lang:[a-z]{2}}/import | admin.translations.import | admin |
| admin | GET | /admin/translations/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*} | admin.translations.show | admin |
| admin | POST | /admin/translations/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*} | admin.translations.save | admin |
| admin | POST | /admin/translations/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*}/approve | admin.translations.approve | admin |
| admin | POST | /admin/translations/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*}/fill | admin.translations.fill | admin |
| admin | GET | /admin/webmcp | admin.webmcp | admin |
| api | GET | /api | api.docs | api.on |
| api | POST | /api/gm/licencia/desactivar | gm.license.deactivate |  |
| api | POST | /api/gm/licencia/verificar | gm.license.verify |  |
| api | GET | /api/openapi.json | api.openapi | api.on |
| api | GET | /api/v1 | api.docs.v1 | api.on |
| api | POST | /api/v1/carts | api.carts.create | api.gate |
| api | POST | /api/v1/carts/checkout/customer | api.checkout.customer | api.gate |
| api | POST | /api/v1/carts/checkout/payment | api.checkout.payment | api.gate |
| api | POST | /api/v1/carts/checkout/payment-options | api.checkout.payment_options | api.gate |
| api | POST | /api/v1/carts/checkout/place | api.checkout.place | api.gate |
| api | POST | /api/v1/carts/checkout/review | api.checkout.review | api.gate |
| api | POST | /api/v1/carts/checkout/shipping | api.checkout.shipping | api.gate |
| api | POST | /api/v1/carts/checkout/shipping-options | api.checkout.shipping_options | api.gate |
| api | POST | /api/v1/carts/checkout/status | api.checkout.status | api.gate |
| api | GET | /api/v1/carts/current | api.carts.show | api.gate |
| api | GET | /api/v1/carts/current/checkout-link | api.carts.checkout_link | api.gate |
| api | POST | /api/v1/carts/current/items | api.carts.items.add | api.gate |
| api | DELETE | /api/v1/carts/current/items/{key:[A-Za-z0-9_-]{1,80}} | api.carts.items.remove | api.gate |
| api | PATCH | /api/v1/carts/current/items/{key:[A-Za-z0-9_-]{1,80}} | api.carts.items.update | api.gate |
| api | GET | /api/v1/categories | api.categories | api.gate |
| api | GET | /api/v1/erp | erp.docs |  |
| api | GET | /api/v1/erp/customers | erp.customers.list | erp.api |
| api | PUT | /api/v1/erp/customers | erp.customers.put | erp.api |
| api | GET | /api/v1/erp/customers/{id:\\d+} | erp.customers.show | erp.api |
| api | GET | /api/v1/erp/invoices | erp.invoices.list | erp.api |
| api | GET | /api/v1/erp/invoices/{number} | erp.invoices.show | erp.api |
| api | GET | /api/v1/erp/invoices/{number}/pdf | erp.invoices.pdf | erp.api |
| api | GET | /api/v1/erp/openapi.json | erp.openapi |  |
| api | GET | /api/v1/erp/orders | erp.orders.list | erp.api |
| api | GET | /api/v1/erp/orders/{number} | erp.orders.show | erp.api |
| api | POST | /api/v1/erp/orders/{number}/ack | erp.orders.ack | erp.api |
| api | POST | /api/v1/erp/orders/{number}/status | erp.orders.status | erp.api |
| api | POST | /api/v1/erp/orders/{number}/tracking | erp.orders.tracking | erp.api |
| api | POST | /api/v1/erp/prices | erp.prices.update | erp.api |
| api | GET | /api/v1/erp/products | erp.products.list | erp.api |
| api | GET | /api/v1/erp/products/{sku:.+} | erp.products.show | erp.api |
| api | PUT | /api/v1/erp/products/{sku:.+} | erp.products.put | erp.api |
| api | GET | /api/v1/erp/stock | erp.stock.list | erp.api |
| api | POST | /api/v1/erp/stock | erp.stock.update | erp.api |
| api | GET | /api/v1/go | api.go.guide | api.gate |
| api | GET | /api/v1/go/{step:[a-z-]{3,20}} | api.go.step | api.gate |
| api | GET | /api/v1/openapi.json | api.openapi.v1 | api.on |
| api | GET | /api/v1/products | api.products.list | api.gate |
| api | GET | /api/v1/products/{id:\\d+} | api.products.show | api.gate |
| api | GET | /api/v1/products/{id:\\d+}/availability | api.products.availability | api.gate |
| api | GET | /api/v1/search | api.search | api.gate |
| api | GET | /api/v1/shipping/quote | api.shipping.quote | api.gate |
| api | GET | /api/v1/store | api.store | api.gate |
| front | GET | / | home |  |
| front | GET | /.well-known/aifec.json | semantic.well_known |  |
| front | GET | /.well-known/apple-developer-merchantid-domain-association | payment.paypal.applepay_domain |  |
| front | GET | /.well-known/mcp.json | mcp.wellknown | mcp.on<br>api |
| front | POST | /asistente/confirmar | assistant.confirm | csrf |
| front | POST | /asistente/mensaje | assistant.message | csrf |
| front | GET | /blog | blog |  |
| front | GET | /blog/feed.xml | blog.feed |  |
| front | GET | /bloque/{id:[pe][0-9]+-[0-9]+} | cms.block |  |
| front | GET | /buscar | search |  |
| front | GET | /buscar.json | search.json |  |
| front | GET | /buscar.md | search.md |  |
| front | GET | /buscar/significado.json | search.meaning |  |
| front | GET | /buscar/sugerencias.json | search.suggest.old |  |
| front | GET | /buscar/sugerir.json | search.suggest |  |
| front | GET | /buy-with-links | api.links.guide | api.gate |
| front | GET | /buy-with-links/{step:[a-z-]{3,20}} | api.links.step | api.gate |
| front | GET | /buy-with-links/{step:[a-z-]{3,20}}/{cart:[a-f0-9]{48}} | api.links.path1 | api.gate |
| front | GET | /buy-with-links/{step:[a-z-]{3,20}}/{cart:[a-f0-9]{48}}/{a:[A-Za-z0-9_.-]{1,80}} | api.links.path2 | api.gate |
| front | GET | /buy-with-links/{step:[a-z-]{3,20}}/{cart:[a-f0-9]{48}}/{a:[A-Za-z0-9_.-]{1,80}}/{b:[A-Za-z0-9_.-]{1,80}} | api.links.path3 | api.gate |
| front | GET | /caja |  |  |
| front | GET | /carrito | cart |  |
| front | POST | /carrito/add | cart.add | csrf |
| front | POST | /carrito/coupon | cart.coupon | csrf |
| front | POST | /carrito/destination | cart.destination | csrf |
| front | POST | /carrito/remove | cart.remove | csrf |
| front | GET | /carrito/retomar/{token:[a-f0-9]{48}} | api.cart.resume | api.on |
| front | GET | /carrito/summary.json | cart.summary |  |
| front | POST | /carrito/update | cart.update | csrf |
| front | GET | /cart |  |  |
| front | POST | /catalogo/elegir-variante | catalog.choose | csrf |
| front | GET | /cesta |  |  |
| front | GET | /checkout |  |  |
| front | POST | /consentimiento | consent.record | csrf |
| front | GET | /cookies | consent.policy |  |
| front | GET | /cron/{key:[A-Za-z0-9_-]+} | jobs.cron |  |
| front | GET | /demo-preview/{package:[a-z0-9-]+} | demos.preview |  |
| front | GET | /demos | demos |  |
| front | GET | /descarga/{token} | order.download |  |
| front | GET | /desistimiento | returns.withdrawal |  |
| front | POST | /desistimiento | returns.lookup | csrf |
| front | POST | /desistimiento/enviar | returns.lookup.submit | csrf |
| front | GET | /devolucion/{number}/{secret} | returns.order |  |
| front | POST | /devolucion/{number}/{secret} | returns.submit | csrf |
| front | POST | /devolucion/{number}/{secret}/cancelar | returns.cancel | csrf |
| front | GET | /docs | docs |  |
| front | GET | /docs/_media/{fichero:[^/]+} | docs.media |  |
| front | GET | /docs/buscar | docs.search |  |
| front | GET | /docs/{ruta:.+} | docs.page |  |
| front | GET | /factura/{number}/{secret} | invoices.download |  |
| front | GET | /favicon.ico |  |  |
| front | GET | /feeds/{file:google\\.xml\|products\\.json\|openai\\.json} | semantic.feed |  |
| front | GET | /finalizar-compra | checkout |  |
| front | POST | /finalizar-compra/place | checkout.place | csrf |
| front | POST | /finalizar-compra/understand | checkout.understand | csrf |
| front | POST | /finalizar-compra/update | checkout.update | csrf |
| front | POST | /formulario/{code:[a-z0-9][a-z0-9_-]*} | form.submit | csrf |
| front | GET | /formulario/{code:[a-z0-9][a-z0-9_-]*}/marca | form.stamp |  |
| front | GET | /llms-full.txt | semantic.llms_full |  |
| front | GET | /llms.txt | semantic.llms |  |
| front | GET | /mcp | mcp.info | mcp.on<br>api |
| front | POST | /mcp | mcp.post | mcp.on<br>api |
| front | GET | /mi-cuenta | account | customer<br>csrf |
| front | GET, POST | /mi-cuenta/datos | account.profile | customer<br>csrf |
| front | GET | /mi-cuenta/descargas | account.downloads | customer<br>csrf |
| front | POST | /mi-cuenta/descargas/{id:\\d+}/enlace | account.downloads.link | customer<br>csrf |
| front | GET | /mi-cuenta/devoluciones/{number} | returns.account | customer<br>csrf |
| front | POST | /mi-cuenta/devoluciones/{number} | returns.account.submit | customer<br>csrf |
| front | POST | /mi-cuenta/devoluciones/{number}/cancelar | returns.account.cancel | customer<br>csrf |
| front | GET | /mi-cuenta/direcciones | account.addresses | customer<br>csrf |
| front | GET, POST | /mi-cuenta/direcciones/nueva | account.address.new | customer<br>csrf |
| front | GET, POST | /mi-cuenta/direcciones/{id:\\d+} | account.address | customer<br>csrf |
| front | POST | /mi-cuenta/direcciones/{id:\\d+}/borrar | account.address.delete | customer<br>csrf |
| front | GET, POST | /mi-cuenta/entrar | account.login | csrf |
| front | POST | /mi-cuenta/entrar/enlace | account.login.link | csrf |
| front | GET | /mi-cuenta/entrar/{token} | account.magic | csrf |
| front | GET | /mi-cuenta/estado.json | account.status | csrf |
| front | GET | /mi-cuenta/licencias | gm.licenses.account | customer<br>csrf |
| front | GET | /mi-cuenta/pedidos | account.orders | customer<br>csrf |
| front | GET | /mi-cuenta/pedidos/{number} | account.order | customer<br>csrf |
| front | GET | /mi-cuenta/privacidad | account.privacy | customer<br>csrf |
| front | GET | /mi-cuenta/privacidad/datos.json | account.privacy.export | customer<br>csrf |
| front | POST | /mi-cuenta/privacidad/eliminar | account.privacy.delete | customer<br>csrf |
| front | GET, POST | /mi-cuenta/recuperar | account.recover | csrf |
| front | GET, POST | /mi-cuenta/registro | account.register | csrf |
| front | GET, POST | /mi-cuenta/restablecer/{token} | account.reset | csrf |
| front | POST | /mi-cuenta/salir | account.logout | csrf |
| front | GET | /mi-cuenta/suscripciones | subscriptions.account | customer<br>csrf |
| front | GET | /mi-cuenta/suscripciones/plan/{id:\\d+} | subscriptions.checkout | customer<br>csrf |
| front | POST | /mi-cuenta/suscripciones/plan/{id:\\d+} | subscriptions.start | customer<br>csrf |
| front | GET | /mi-cuenta/suscripciones/vuelta/{id} | subscriptions.back | customer<br>csrf |
| front | POST | /mi-cuenta/suscripciones/{id:\\d+}/cancelar | subscriptions.cancel | customer<br>csrf |
| front | POST | /mi-cuenta/verificacion/reenviar | account.verify.resend | customer<br>csrf |
| front | GET | /mi-cuenta/verificar/{token} | account.verify | csrf |
| front | POST | /pago/paypal/aviso | payment.paypal.notify |  |
| front | GET | /pago/paypal/cancelado/{number}/{secret} | payment.paypal.cancelled |  |
| front | POST | /pago/paypal/capturar/{number}/{secret} | payment.paypal.capture | csrf |
| front | POST | /pago/paypal/orden/{number}/{secret} | payment.paypal.create | csrf |
| front | GET | /pago/paypal/pagar/{number}/{secret} | payment.paypal.pay |  |
| front | GET | /pago/paypal/vuelta/{number}/{secret} | payment.paypal.back |  |
| front | POST | /pago/redsys/aviso | payment.redsys.notify |  |
| front | GET | /pago/redsys/ko/{number}/{secret} | payment.redsys.ko |  |
| front | GET | /pago/redsys/ok/{number}/{secret} | payment.redsys.ok |  |
| front | GET | /pago/simulado/{number}/{secret} | payment.simulated.pay |  |
| front | POST | /pago/simulado/{number}/{secret} | payment.simulated.decide | csrf |
| front | GET | /pedido/{number}/{secret} | order.thanks |  |
| front | POST | /pedido/{number}/{secret}/download/{id:\\d+} | order.thanks.download | csrf |
| front | GET | /pedido/{number}/{secret}/pay | order.pay |  |
| front | POST | /proximamente/avisame | gm.soon.notify | csrf |
| front | GET | /robots.txt | seo.robots |  |
| front | GET | /sitemap-{type:pages\|posts\|products\|categories\|brands}-{n:\\d+}\\.xml | seo.sitemap.part |  |
| front | GET | /sitemap.xml | seo.sitemap |  |
| front | POST | /suscripciones/paypal/{environment:sandbox\|live}/aviso | subscriptions.notify |  |
| front | GET | /suscripciones/planes | subscriptions.plans |  |
| front | GET | /tema/muestra | theme.sample |  |
| front | POST | /tema/muestra |  |  |
| front | GET | /textos-con-ia | gm.aviso_ia |  |
| front | POST | /valoracion/{productId:\\d+} | reviews.submit | csrf |
| front | GET | /valorar/{token} | reviews.request |  |
| front | POST | /valorar/{token} |  | csrf |
| front | POST | /webmcp/call/{name:[a-z][a-z0-9_]{1,63}} | webmcp.call | csrf |
| front | GET | /webmcp/tools.json | webmcp.tools |  |
