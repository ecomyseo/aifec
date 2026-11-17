# Permisos

Los permisos que existen y dónde se piden: recursos, pantallas de ajustes, menús y paneles de los `admin.php` y `settings.php`, y los que se dan a los papeles sembrados. `*` es todo y `area.*` es toda un área.

Generado con `php aifec docs:reference`. No lo edites a mano.

| Permiso | Área | Dónde se pide |
| --- | --- | --- |
| * | * | role admin |
| ai.edit | ai | declared in ai<br>menu ai<br>settings ai |
| ai.use | ai | declared in ai<br>menu ai<br>role demo<br>role editor<br>role manager |
| ai.view | ai | dashboard ai<br>declared in ai<br>menu ai<br>role demo<br>role manager |
| api.edit | api | declared in api<br>resource api_keys<br>settings api |
| api.view | api | declared in api<br>menu api<br>resource api_keys |
| appearance.create | appearance | declared in themes<br>menu themes |
| appearance.edit | appearance | declared in themes<br>role demo |
| appearance.view | appearance | declared in themes<br>menu themes<br>role demo |
| assistant.edit | assistant | declared in assistant<br>menu assistant<br>settings assistant |
| assistant.view | assistant | declared in assistant<br>menu assistant<br>role demo<br>role manager<br>role support |
| catalog.* | catalog | role demo<br>role manager |
| catalog.edit | catalog | declared in catalog<br>menu catalog<br>menu tools<br>resource attributes<br>resource brands<br>resource categories<br>resource families<br>… +2 |
| catalog.view | catalog | dashboard catalog<br>declared in catalog<br>menu catalog<br>resource attributes<br>resource brands<br>resource categories<br>resource families<br>resource products |
| consent.edit | consent | declared in consent<br>menu consent<br>resource consent_cookies<br>settings consent |
| consent.view | consent | dashboard consent<br>declared in consent<br>menu consent<br>resource consent_cookies |
| content.* | content | role demo<br>role editor<br>role manager |
| content.edit | content | declared in cms<br>menu cms<br>resource form_entries<br>resource forms<br>resource pages<br>resource post_categories<br>resource post_tags<br>… +3 |
| content.publish | content | declared in cms |
| content.view | content | declared in cms<br>menu cms<br>resource form_entries<br>resource forms<br>resource pages<br>resource post_categories<br>resource post_tags<br>… +2 |
| copilot.use | copilot | declared in copilot<br>menu copilot |
| coupons.* | coupons | role demo<br>role manager |
| coupons.edit | coupons | declared in coupons<br>resource coupons |
| coupons.view | coupons | declared in coupons<br>menu coupons<br>resource coupons |
| customers.* | customers | role manager |
| customers.edit | customers | declared in customers<br>resource customer_groups<br>resource customers<br>settings customers |
| customers.export | customers | declared in customers |
| customers.privacy | customers | declared in customers |
| customers.view | customers | declared in customers<br>menu customers<br>resource customer_groups<br>resource customers<br>role demo<br>role support |
| dashboard.* | dashboard | role demo<br>role manager |
| dashboard.view | dashboard | declared in core<br>role editor<br>role support |
| datalayer.edit | datalayer | declared in datalayer<br>menu datalayer<br>settings datalayer |
| datalayer.view | datalayer | declared in datalayer<br>menu datalayer |
| erp.edit | erp | declared in erp<br>menu erp<br>settings erp |
| erp.view | erp | declared in erp<br>menu erp |
| gmlegal.edit | gmlegal | declared in gm-legal-producto<br>menu gm-legal-producto<br>resource gm_legal_blocks<br>settings gm-legal-producto |
| gmlegal.view | gmlegal | declared in gm-legal-producto<br>menu gm-legal-producto<br>resource gm_legal_blocks |
| gmlic.edit | gmlic | declared in gm-licencias<br>menu gm-licencias<br>resource gm_licenses<br>settings gm-licencias |
| gmlic.view | gmlic | declared in gm-licencias<br>menu gm-licencias<br>resource gm_licenses |
| gmsoon.edit | gmsoon | declared in gm-proximamente<br>menu gm-proximamente<br>resource gm_soon_requests<br>settings gm-proximamente |
| gmsoon.view | gmsoon | declared in gm-proximamente<br>menu gm-proximamente<br>resource gm_soon_requests |
| import.run | import | declared in import<br>menu import<br>settings import |
| invoices.* | invoices | role manager |
| invoices.edit | invoices | declared in invoices<br>menu invoices<br>settings invoices |
| invoices.view | invoices | declared in invoices<br>menu invoices<br>role demo<br>role support |
| jobs.edit | jobs | declared in jobs<br>resource jobs<br>settings jobs |
| jobs.view | jobs | declared in jobs<br>menu jobs<br>resource jobs |
| mail.edit | mail | declared in mail<br>menu mail<br>resource mail<br>settings mail |
| mail.view | mail | declared in mail<br>menu mail<br>resource mail |
| mcp.edit | mcp | declared in mcp<br>settings mcp |
| media.* | media | role demo<br>role editor<br>role manager |
| media.edit | media | declared in media<br>settings media |
| media.view | media | declared in media<br>menu media |
| orders.* | orders | role demo<br>role manager |
| orders.create | orders | declared in orders |
| orders.edit | orders | declared in orders<br>menu orders<br>resource order_states<br>settings orders<br>settings payments-cod<br>settings payments-paypal<br>settings payments-redsys<br>… +2 |
| orders.export | orders | declared in orders |
| orders.notes | orders | declared in orders<br>role support |
| orders.price_override | orders | declared in orders |
| orders.refund | orders | declared in orders |
| orders.view | orders | dashboard orders<br>declared in orders<br>menu orders<br>resource order_states<br>role support |
| plugins.edit | plugins | declared in plugins<br>menu plugins |
| plugins.view | plugins | declared in plugins<br>menu plugins<br>role demo |
| returns.* | returns | role manager |
| returns.manage | returns | declared in returns<br>menu returns<br>settings returns |
| returns.view | returns | declared in returns<br>menu returns<br>role support |
| reviews.* | reviews | role demo<br>role manager |
| reviews.edit | reviews | declared in reviews<br>menu reviews<br>settings reviews |
| reviews.view | reviews | declared in reviews<br>menu reviews<br>role editor<br>role support |
| search.edit | search | declared in search<br>menu search<br>resource synonyms<br>settings search |
| search.view | search | declared in search<br>menu search<br>resource synonyms<br>role demo<br>role manager |
| search_learning.manage | search_learning | declared in search-learning<br>settings search-learning |
| search_learning.view | search_learning | declared in search-learning<br>menu search-learning |
| semantic.view | semantic | dashboard semantic<br>declared in semantic<br>menu semantic |
| seo.* | seo | role demo<br>role editor<br>role manager |
| seo.edit | seo | declared in seo<br>menu seo<br>settings semantic<br>settings seo |
| seo.view | seo | dashboard seo<br>declared in seo<br>menu seo |
| settings.edit | settings | declared in core<br>menu core<br>settings copilot<br>settings core<br>settings gm-aviso-ia<br>settings hello-aifec |
| shipping.edit | shipping | declared in shipping<br>resource carriers<br>resource shipping_zones<br>settings shipping |
| shipping.view | shipping | declared in shipping<br>menu shipping<br>resource carriers<br>resource shipping_zones<br>role demo<br>role manager |
| sites.manage | sites | declared in sites<br>menu sites |
| subscriptions.edit | subscriptions | declared in subscriptions<br>menu subscriptions<br>resource subscription_plans<br>settings subscriptions |
| subscriptions.view | subscriptions | declared in subscriptions<br>menu subscriptions<br>resource subscription_plans |
| system.edit | system | resource audit |
| system.view | system | declared in core<br>menu core<br>resource audit |
| tax.edit | tax | declared in tax<br>menu tax<br>resource tax_classes<br>resource tax_rates<br>settings tax |
| tax.view | tax | declared in tax<br>menu tax<br>resource tax_classes<br>resource tax_rates<br>role demo<br>role manager |
| team.edit | team | declared in core<br>resource roles<br>resource users |
| team.view | team | declared in core<br>menu core<br>resource roles<br>resource users |
| tools.backup | tools | declared in tools<br>menu tools |
| tools.edit | tools | declared in tools<br>settings tools |
| tools.restore | tools | declared in tools |
| tools.view | tools | declared in tools<br>menu tools<br>role demo |
| translations.edit | translations | declared in translations<br>menu translations<br>settings translations |
| translations.view | translations | declared in translations |
| webmcp.edit | webmcp | declared in webmcp<br>menu webmcp<br>settings webmcp |
| webmcp.view | webmcp | declared in webmcp<br>menu webmcp |
