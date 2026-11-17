# Tablas

Las tablas de la base del sitio (sin el prefijo) con el módulo que las crea y sus columnas reales.

Generado con `php aifec docs:reference`. No lo edites a mano.

| Tabla | Módulo | Columnas |
| --- | --- | --- |
| addresses | customers | id, customer_id, label, first_name, last_name, company, vat_id, phone, line1, line2, postcode, city, region, country, default_shipping, default_billing, created_at, updated_at |
| ai_batches | ai | id, task, filter, options, total, done, failed, streak, last_id, status, reason, created_by, created_at, updated_at, finished_at |
| ai_marks | ai | id, entity, entity_id, field, lang, user_id, model, created_at |
| ai_proposals | ai | id, entity, entity_id, field, lang, current_text, proposed_text, status, model, task, created_by, resolved_by, created_at, resolved_at |
| ai_usage | ai | id, day, task, tier, provider, model, tokens_in, tokens_out, cost_micro, ms, ok, error, created_at |
| api_keys | core | id, name, key_hash, scopes, active, last_used_at, created_at |
| attribute_i18n | catalog | id, attribute_id, lang, name |
| attribute_value_i18n | catalog | id, value_id, lang, label |
| attribute_values | catalog | id, attribute_id, code, color, media_id, position |
| attributes | catalog | id, code, type, unit, filterable, visible, variant, position |
| audit | core | id, user_id, user_name, action, entity, entity_id, summary, changes, ip, created_at |
| audit_scores | semantic | entity, entity_id, score, fails, checked_at |
| bot_offsets | semantic | file, pos, updated_at |
| bot_visits | semantic | id, day, kind, name, family, page_class, visits, last_at |
| brands | catalog | id, name, description, website, media_id, active, position, created_at, updated_at |
| carrier_rates | shipping | id, carrier_id, zone_id, basis, min, max, price |
| carriers | shipping | id, code, name, delay, handler, settings, tax_class_id, free_from, max_weight, min_days, max_days, tracking_url, media_id, active, position, created_at, updated_at |
| carts | orders | id, token, customer_id, lang, items, coupons, destination, carrier_id, payment_method, email, checkout, source, order_id, created_at, updated_at |
| categories | catalog | id, parent_id, path, depth, position, active, in_menu, media_id, product_count, created_at, updated_at |
| category_i18n | catalog | id, category_id, lang, name, description, facts, meta_title, meta_description |
| consent_cookies | consent | id, name, provider, purpose, duration, category, builtin, position |
| consent_log | consent | id, cookie_id, policy_version, categories, action, gpc, lang, created_at |
| consents | customers | id, customer_id, email, kind, granted, version, context, ip, created_at |
| conversation_messages | assistant | id, conversation_id, role, content, tools, unanswered, phrase, created_at, ai_ms, tools_ms, rounds |
| conversations | assistant | id, token_hash, lang, mode, started_at, last_at, messages, unanswered, cart_added, checkout, order_id, state |
| copilot_proposals | copilot | id, user_id, user_name, text, action, args, preview, status, fingerprint, undo, result, source, created_at, updated_at, applied_at, undone_at |
| coupon_uses | coupons | id, coupon_id, order_id, customer_id, email, amount, created_at |
| coupons | coupons | id, code, kind, percent, amount, min_subtotal, starts_at, ends_at, max_uses, max_uses_per_customer, uses, first_order_only, exclude_sale, combinable, customer_id, products, categories, note, active, created_at, updated_at |
| cron_runs | core | task, last_run_at, last_ok_at, last_error, runs |
| customer_groups | customers | id, name, discount, tax_exempt, is_default |
| customers | customers | id, email, password, guest, first_name, last_name, company, vat_id, phone, group_id, locale, active, marketing, marketing_at, notes, orders_count, total_spent, source, legacy_id, email_verified_at, last_login_at, created_at, updated_at |
| datalayer_events | datalayer | id, order_id, kind, destination, ref, event_id, status, http_status, response, created_at, sent_at |
| datalayer_orders | datalayer | id, order_id, event_id, consent, ga_client_id, fbp, fbc, ai_source, assistant, browser_at, created_at |
| erp_acks | erp | id, order_id, connection_id, external_id, acked_at |
| erp_connections | erp | id, name, api_key_id, key_hint, scopes, active, revoked_at, last_used_at, created_at, updated_at |
| erp_cursors | erp | scope, value, updated_at |
| erp_deliveries | erp | id, webhook_id, connection_id, event, event_id, payload, status, attempts, response_code, error, next_attempt_at, sent_at, created_at, updated_at |
| erp_export_runs | erp | id, profile_id, direction, trigger_kind, status, records, bytes, destination, file, file_name, expires_at, error, report, started_at, finished_at |
| erp_exports | erp | id, name, direction, kind, enabled, format, columns, options, filter, destination, dest, schedule, schedule_every, schedule_at, next_run_at, last_run_at, created_at, updated_at |
| erp_idempotency | erp | id, connection_id, idem_key, fingerprint, status_code, body, created_at |
| erp_webhooks | erp | id, connection_id, url, events, active, paused, paused_reason, failures, since_at, created_at, updated_at |
| families | catalog | id, name, position |
| family_attributes | catalog | family_id, attribute_id, position |
| form_entries | cms | id, form_id, data, ip, status, created_at |
| forms | cms | id, code, name, fields, settings, created_at, updated_at |
| gm_legal_assignments | gm-legal-producto | id, block_id, target_type, target_id |
| gm_legal_blocks | gm-legal-producto | id, code, title, kind, body, active, in_mail, position, created_at, updated_at |
| gm_legal_orders | gm-legal-producto | order_id, waiver, version, created_at, mailed_at |
| gm_license_checks | gm-licencias | id, license_id, action, result, created_at |
| gm_license_domains | gm-licencias | id, license_id, domain, activated_at, last_seen_at |
| gm_license_products | gm-licencias | product_id, kind, max_domains, update_months, updated_at |
| gm_licenses | gm-licencias | id, license_key, kind, status, order_id, order_item_id, unit, customer_id, email, product_id, product_name, max_domains, units, holder, updates_until, note, created_at, updated_at, revoked_at |
| gm_soon_products | gm-proximamente | product_id, soon, release_at, updated_at |
| gm_soon_requests | gm-proximamente | id, product_id, email, lang, consented_at, created_at, notified_at |
| hello_aifec_made_in | hello-aifec | product_id, country, updated_at |
| import_log | import | id, run_id, type, source_id, result, reason, created_at |
| import_map | import | id, source, source_key, type, source_id, local_id, source_hash, run_id, written_at |
| import_runs | import | id, source, mode, source_key, label, options, dry_run, status, phase, current_type, cursor, counters, equivalences, unmapped, checksums, pause_reason, source_errors, user_id, started_at, heartbeat_at, finished_at, created_at, updated_at |
| invoices | invoices | id, unique_key, order_id, type, series, number, full_number, issued_at, refund_id, original_id, currency, net, tax, total, test, data, pdf_path, created_at, updated_at |
| jobs | core | id, queue, type, payload, status, attempts, max_attempts, run_at, locked_at, finished_at, error, result, unique_key, created_at |
| learning_applications | search-learning | id, run_id, context_hash, version_before, version_after, memory_before, memory_after, reviewed_by, status, created_at, reverted_at |
| learning_calls | search-learning | id, run_id, call_key, state, reserved_micro, actual_micro, tokens_in, tokens_out, request_hash, usage_id, created_at, updated_at |
| learning_cases | search-learning | id, run_id, kind, query, profile, target_ids, evidence, before_result, after_result, verdict, verifiable |
| learning_proposals | search-learning | id, run_id, case_id, context_hash, version, kind, source, target, product_ids, evidence, reason, status |
| learning_runs | search-learning | id, site_id, context_hash, context, lang, mode, status, initiator_id, resumed_by, options, catalogue, provider_config, budget_micro, spent_micro, reserved_micro, calls, tokens_in, tokens_out, done, total, version, applied_version, generation, validation, error, lease, locked_at, created_at, updated_at |
| learning_states | search-learning | id, context_hash, version, memory, updated_at |
| login_attempts | core | id, guard, identifier, ip, ok, created_at |
| mail_messages | core | id, to_email, to_name, subject, template, locale, data, html, text_body, status, attempts, error, related, created_at, sent_at |
| media | core | id, path, filename, mime, kind, bytes, width, height, hash, dominant, alt, title, variants, folder, created_by, created_at |
| menu_items | cms | id, menu_id, parent_id, position, type, ref, url, blank, labels |
| menus | cms | id, code, name |
| migrations | core | scope, name, applied_at |
| not_found | core | path, hits, referer, first_at, last_at |
| order_downloads | orders | id, order_id, order_item_id, file_id, token, downloads, max_downloads, expires_at, last_at, created_at |
| order_history | orders | id, order_id, kind, from_value, to_value, note, is_public, user_id, actor, data, created_at |
| order_items | orders | id, order_id, kind, product_id, variant_id, sku, name, variant_label, qty, unit_price, discount, line_total, line_net, line_tax, tax_rate, tax_name, weight, is_digital, refunded_qty, refunded_total, data, position |
| order_payments | orders | id, order_id, method, kind, reference, amount, currency, status, test, raw, created_at |
| order_recurring_payments | orders | external_key, fingerprint, order_id, created_at |
| order_refunds | orders | id, order_id, amount, tax, items, reason, restock, payment_id, user_id, created_at |
| order_states | orders | id, code, name, kind, color, mail, system, position |
| orders | orders | id, number, customer_id, email, lang, currency, status, payment_status, fulfillment_status, payment_method, payment_title, carrier_id, carrier_title, tracking_code, prices_include_tax, items_total, discount_total, shipping_total, fee_total, total_net, total_tax, total, paid_total, refunded_total, taxes, adjustments, billing, shipping, coupons, customer_note, source, attribution, secret, cart_id, test, ip, legacy_id, placed_at, paid_at, shipped_at, completed_at, cancelled_at, created_at, updated_at |
| page_i18n | cms | id, page_id, lang, title, blocks, excerpt, meta_title, meta_description |
| pages | cms | id, status, template, parent_id, position, noindex, is_home, published_at, author_id, created_at, updated_at |
| post_i18n | cms | id, post_id, lang, title, blocks, excerpt, meta_title, meta_description |
| post_terms | cms | post_id, term_id |
| posts | cms | id, status, media_id, author_id, noindex, published_at, created_at, updated_at |
| product_attributes | catalog | id, product_id, attribute_id, value_id, lang, value_text, value_number |
| product_categories | catalog | product_id, category_id, is_main, position |
| product_faqs | catalog | id, product_id, lang, question, answer, position |
| product_files | catalog | id, product_id, variant_id, name, path, bytes, version, position, created_at |
| product_i18n | catalog | id, product_id, lang, name, summary, description, facts, meta_title, meta_description |
| product_media | catalog | product_id, media_id, variant_id, position |
| product_relations | catalog | product_id, related_id, kind, position |
| product_tags | catalog | product_id, tag_id |
| products | catalog | id, kind, has_variants, sku, gtin, mpn, brand_id, family_id, tax_class_id, price, compare_price, sale_price, sale_from, sale_to, cost, manage_stock, stock, stock_alert, backorders, weight, length, width, height, min_qty, max_qty, delivery_min_days, delivery_max_days, status, visibility, featured, main_media_id, position, rating_sum, rating_count, sales_count, extra, published_at, created_at, updated_at |
| rate_limits | core | k, hits, window_start |
| recovery_codes | core | id, user_id, hash, used_at, created_at |
| redirects | core | id, source, target, code, hits, active, note, created_at |
| return_events | returns | id, return_id, kind, from_value, to_value, note, actor, user_id, notified, created_at |
| return_items | returns | id, return_id, order_item_id, product_id, variant_id, sku, name, variant_label, qty, restock, refunded |
| returns | returns | id, number, order_id, customer_id, email, lang, currency, kind, status, reason, comment, photos, full_order, instructions, reject_reason, refunded_amount, open_key, source, requested_at, approved_at, received_at, refunded_at, closed_at, created_at, updated_at |
| review_requests | reviews | id, order_id, product_id, email, name, lang, token, status, send_at, sent_at, used_at, review_id, created_at |
| reviews | reviews | id, product_id, order_id, customer_id, name, email, rating, title, body, lang, status, verified, reply, replied_at, published_at, created_at, updated_at |
| revisions | cms | id, entity, entity_id, lang, data, user_id, created_at |
| roles | core | id, code, name, caps, system |
| search_docs | search | id, type, entity_id, lang, label, name_norm, sales, in_stock, len_name, len_ref, len_brand, len_cats, len_tags, len_attrs, len_summary, len_desc, len_extra, extra_terms, src_hash, vector, indexed_at |
| search_filters | search | id, day, lang, kind, hits |
| search_log | search | id, day, lang, phrase, hits, found, missed, outcome |
| search_postings | search | term_id, doc_id, f_name, f_ref, f_brand, f_cats, f_tags, f_attrs, f_summary, f_desc, f_extra |
| search_terms | search | id, lang, term, surface, len, df |
| sequences | orders | name, value |
| sessions | core | id, guard, user_id, data, created_at, last_at, expires_at |
| settings | core | k, v |
| shipping_zones | shipping | id, name, active, position |
| slugs | core | id, lang, path, type, entity_id, canonical |
| stock_moves | catalog | id, product_id, variant_id, delta, stock_after, reason, reference, created_at |
| subscription_events | subscriptions | id, event_key, resource_key, subscription_id, kind, digest, payment_key, body, processed, created_at |
| subscription_payments | subscriptions | id, subscription_id, payment_key, reference, amount, currency, status, refunded_amount, order_id, paid_at |
| subscription_plans | subscriptions | id, name, description, amount, tax_class_id, active, created_at, updated_at |
| subscriptions | subscriptions | id, public_id, plan_id, customer_id, status, provider, environment, provider_id, provider_plan_id, approval_url, snapshot, consented_at, next_payment_at, cancelled_at, version, failures, created_at, updated_at |
| synonyms | search | id, lang, mode, source, terms, created_at, updated_at |
| tags | catalog | id, lang, name |
| tax_classes | tax | id, code, name, is_default, position |
| tax_rates | tax | id, tax_class_id, country, region, postcodes, rate, name, priority, active |
| term_i18n | cms | id, term_id, lang, name, description |
| terms | cms | id, taxonomy, parent_id, position |
| tokens | core | id, kind, subject, hash, data, expires_at, used_at, created_at |
| translation_jobs | translations | id, lang, scope, status, total, done, failed, streak, chunks, cursor, reason, created_by, created_at, updated_at, finished_at |
| translation_proposals | translations | id, lang, scope, item_hash, item, source, text, job_id, created_at |
| users | core | id, email, name, password, role, locale, totp_secret, active, last_login_at, created_at, updated_at |
| variant_values | catalog | variant_id, attribute_id, value_id |
| variants | catalog | id, product_id, sku, gtin, price, compare_price, cost, stock, weight, media_id, label, position, active, created_at, updated_at |
| withdrawals | customers | id, order_id, customer_id, status, items, reason, resolution, created_at, resolved_at |
| zone_locations | shipping | id, zone_id, country, region, postcodes |
