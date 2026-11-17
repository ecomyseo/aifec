# Órdenes de consola

Todas las órdenes de `php aifec <orden>` de lo que hay cargado y el módulo que declara cada una. Las que no son del núcleo salen del `cli.php` de su módulo o plugin.

Generado con `php aifec docs:reference`. No lo edites a mano.

| Orden | Qué hace | Módulo |
| --- | --- | --- |
| aifec:audit | AIFEC audit: the shop score and the checks, then measures the products: aifec:audit [--queue] | semantic |
| assets:publish | Copies static assets to the public folder | core |
| backup | Creates a backup of this site and checks it: backup [--mode=full\|db] | tools |
| backup:restore | Restores a backup (after making a copy of what is there): backup:restore &lt;file&gt; [--yes] [--only=db\|files] [--other-site] | tools |
| backup:verify | Checks a backup without restoring it: backup:verify &lt;file&gt; | tools |
| cache:clear | Empties the site cache (data and pages) | core |
| catalog:replace-text | Finds a text in the product names, summaries and descriptions and, with --apply, replaces it: catalog:replace-text "&lt;text&gt;" "&lt;with&gt;" [--apply] | catalog |
| catalog:restock | Tops up the stock of what is running out: catalog:restock [--below=3] [--to=20] [--apply] (every change goes to the stock moves) | catalog |
| cms:legal | The legal pages: shows them; --create creates the missing ones, --refresh rewrites the existing ones with the current texts (--only=code,code for some), --publish publishes them | cms |
| contract:check | Checks a plugin against the AIFEC contract: contract:check &lt;id&gt; | plugins |
| cron | Runs the scheduled tasks and then the queue: cron [--seconds=50] | jobs |
| demo:adopt | Lists an existing demo site on the demos page: demo:adopt &lt;site&gt; [--name=…] [--sector=…] [--description=…] | sites |
| demo:check | Checks a demo package against the schema without building anything: demo:check &lt;package&gt; | sites |
| demo:create | Builds a demo shop in /demos/&lt;folder&gt;: demo:create &lt;package&gt; &lt;folder&gt; [--name=…] [--password=…] [--administration] | sites |
| demo:list | Lists the sites and the demos with their package and state | sites |
| demo:reset | Deletes a demo and builds it again from its package: demo:reset &lt;id&gt; | sites |
| docs:reference | Writes the developer reference (commands, settings, routes, permissions, hooks, tables) from what the system declares: docs:reference [--out=docs/07-referencia/_generado] | core |
| docs:refresh | Forgets the saved copies of the documentation pages so the next visit reads the Markdown files again | docs |
| doctor | Checks the system and exits with 1 if something is wrong | tools |
| erp:deliveries | Shows the pending and failed messages to the ERP | erp |
| erp:export | Runs an ERP export now: erp:export &lt;profile id or name&gt; | erp |
| erp:import | Reads the pending files of an ERP import: erp:import &lt;profile id or name&gt; | erp |
| help | Lists the available commands | core |
| i18n:check | Checks that every text of a folder has a translation: i18n:check &lt;core\|modules/x\|plugins/x\|themes/x&gt; [lang] [--unused] | core |
| import:cancel | Cancels a migration that has not finished: import:cancel [id] | import |
| import:pause | Pauses the migration in progress: import:pause [id] | import |
| import:probe | Tests the connection to a source shop and counts what is there: import:probe --source=… --mode=… | import |
| import:report | Writes the notes of a migration as CSV: import:report [id] [--out=file.csv] | import |
| import:resume | Resumes a paused migration and works on it: import:resume [id] | import |
| import:run | Migrates a shop (or simulates it with --dry-run): import:run --source=woocommerce\|prestashop --mode=api\|public\|db [--dry-run] [--yes] [--only=a,b] [--types=a,b] [--since=YYYY-MM-DD] [--shop=ID] [--source-host=domain] [--order-prefix=TEXT] [--team-admins=0\|1] [--keep-urls=0\|1] [--no-images] [--overwrite-local] [--label=TEXT] [--ask] [--db-host=H] [--db-port=N] [--db-name=NAME] [--db-user=USER] [--db-password=KEY] and the fields of the reader (--url, --key, --secret…) | import |
| import:status | Shows how a migration goes: import:status [id] | import |
| import:verify | Shows the final check of a migration: import:verify [id] | import |
| jobs:retry | Sends a failed job back to the queue: jobs:retry &lt;id&gt; | jobs |
| jobs:run | Runs a scheduled task now: jobs:run &lt;task&gt; | jobs |
| jobs:status | Shows the jobs by state and the last cron run | jobs |
| jobs:work | Works through the queue: jobs:work [--seconds=20] | jobs |
| mail:send | Sends the queued email | mail |
| mail:status | Shows how many messages are in each state | mail |
| mail:test | Sends the test email: mail:test &lt;email&gt; | mail |
| mcp:call | Calls an MCP tool the way a client would: mcp:call &lt;tool&gt; '&lt;json arguments&gt;' [session id] | mcp |
| migrate | Applies pending database migrations | core |
| modules | Lists modules and plugins and whether they are loaded | core |
| package:apply | Applies the pages, menus and theme of a package to an existing site; it never deletes anything: package:apply &lt;package&gt; [--only=pages,menus,theme] [--dry-run] (the site: --site=&lt;id&gt;, the master by default) | sites |
| plugin:create | Creates a plugin from a template: plugin:create &lt;id&gt; [--name=…] [--features=setting,screen,listener,slot,block,job,command,payment,carrier\|all] | plugins |
| plugin:disable | Turns a module or plugin off: plugin:disable &lt;id&gt; | plugins |
| plugin:enable | Turns a module or plugin on: plugin:enable &lt;id&gt; | plugins |
| plugin:grant | Grants a capability to a plugin: plugin:grant &lt;id&gt; &lt;capability&gt; [--off] | plugins |
| plugin:install | Installs a plugin from a ZIP (it is not turned on): plugin:install &lt;file.zip&gt; | plugins |
| plugin:uninstall | Uninstalls a turned-off plugin: plugin:uninstall &lt;id&gt; [--keep-data] | plugins |
| search:reindex | Rebuilds the search index: search:reindex [product\|page\|post] [--queue] | search |
| setting:get | Shows a setting: setting:get &lt;key&gt; | core |
| setting:set | Changes a setting: setting:set &lt;key&gt; &lt;value&gt; (true, false, numbers and JSON are understood; --text keeps it as text) | core |
| shipping:rate | Gives a carrier a rate for a zone: shipping:rate &lt;carrier code&gt; "&lt;zone name&gt;" &lt;price in cents&gt; [--min=0] [--max=40000] [--basis=weight\|price] | shipping |
| site:config | Changes keys of a site config: site:config &lt;id&gt; url=… locale=… timezone=… debug=0\|1 demo=0\|1 master=0\|1 modules_only=a,b | core |
| site:create | Creates a site: site:create &lt;id&gt; --url=https://… [--path=/demos/moda] [--host=] [--name=] [--email=] [--admin-email= --admin-name= --admin-password=] [--lang=es] [--timezone=] [--db=sqlite\|mysql --db-host= --db-port= --db-name= --db-user= --db-pass= --db-prefix=] [--demo] [--master] [--modules=a,b] | core |
| site:delete | Deletes a site and its folder: site:delete &lt;id&gt; --yes [--drop-tables] | core |
| sites | Lists the sites of this installation | core |
| sites:owners | Lets the administrators of this site sign in to the panel of every demo with their own account: sites:owners [&lt;site&gt;] | sites |
| theme:activate | Makes a theme the active one, if it passes the validator: theme:activate &lt;slug&gt; | themes |
| theme:check | Runs the theme validator: theme:check &lt;slug&gt; | themes |
| theme:create | Creates a child theme in themes/&lt;slug&gt;: theme:create &lt;slug&gt; [--name=…] [--parent=base] [--accent=#…] [--bg=#…] [--font=…] | themes |
| translations:approve | Publishes what the AI proposed in a language: translations:approve &lt;lang&gt; [--only=products,categories,…] | translations |
| translations:export | Writes the interface texts of a language to a CSV file: translations:export &lt;lang&gt; [--out=file.csv] | translations |
| translations:fill | Queues the AI translation of what is missing: translations:fill &lt;lang&gt; [--only=interface,products,…] | translations |
| translations:import | Reads a CSV file into the interface texts of a language: translations:import &lt;lang&gt; &lt;file.csv&gt; | translations |
| translations:status | Shows how much is translated in each language: translations:status [lang] | translations |
| user:2fa-reset | Turns off two-step verification for someone locked out: user:2fa-reset &lt;email&gt; | core |
| user:create | Creates a team member: user:create &lt;email&gt; &lt;name&gt; [--role=admin] [--password=…] | core |
| user:password | Sets a new password: user:password &lt;email&gt; [--password=…] | core |
| version | Shows the version | core |
