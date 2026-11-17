<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Las tablas de PrestaShop que lee el lector, con las columnas reales de install/data/db_structure.sql de la 8.2.7 simplificadas a
 * dos tipos (entero y texto: los importes quedan como texto, tal cual los guarda PrestaShop con seis decimales). Variantes: «1.7»
 * (la 1.7.8: producto sin unit_price, transportista con id_tax_rules_group, pedidos con shipping_number…) y «9.1» (columnas nuevas
 * de cart_rule y feature_value, sin meta_keywords).
 *
 * @return array<string,string> tabla => columnas separadas por comas
 */
function psTables(string $variant = '8.2'): array
{
    $tables = [
        'configuration' => 'id_configuration,id_shop_group,id_shop,name,value,date_add,date_upd',
        'configuration_lang' => 'id_configuration,id_lang,value,date_upd',
        'shop' => 'id_shop,id_shop_group,name,color,id_category,theme_name,active,deleted',
        'shop_url' => 'id_shop_url,id_shop,domain,domain_ssl,physical_uri,virtual_uri,main,active',
        'lang' => 'id_lang,name,active,iso_code,language_code,locale,date_format_lite,date_format_full,is_rtl',
        'currency' => 'id_currency,name,iso_code,numeric_iso_code,precision,conversion_rate,deleted,active,unofficial,modified',
        'country' => 'id_country,id_zone,id_currency,iso_code,call_prefix,active,contains_states,need_identification_number,need_zip_code,zip_code_format,display_tax_label',
        'state' => 'id_state,id_country,id_zone,name,iso_code,tax_behavior,active',
        'zone' => 'id_zone,name,active',
        'tax' => 'id_tax,rate,active,deleted',
        'tax_lang' => 'id_tax,id_lang,name',
        'tax_rules_group' => 'id_tax_rules_group,name,active,deleted,date_add,date_upd',
        'tax_rules_group_shop' => 'id_tax_rules_group,id_shop',
        'tax_rule' => 'id_tax_rule,id_tax_rules_group,id_country,id_state,zipcode_from,zipcode_to,id_tax,behavior,description',
        'carrier' => 'id_carrier,id_reference,name,url,active,deleted,shipping_handling,range_behavior,is_module,is_free,shipping_external,need_range,external_module_name,shipping_method,position,max_width,max_height,max_depth,max_weight,grade',
        'carrier_lang' => 'id_carrier,id_shop,id_lang,delay',
        'carrier_zone' => 'id_carrier,id_zone',
        'carrier_shop' => 'id_carrier,id_shop',
        'carrier_tax_rules_group_shop' => 'id_carrier,id_tax_rules_group,id_shop',
        'range_price' => 'id_range_price,id_carrier,delimiter1,delimiter2',
        'range_weight' => 'id_range_weight,id_carrier,delimiter1,delimiter2',
        'delivery' => 'id_delivery,id_shop,id_shop_group,id_carrier,id_range_price,id_range_weight,id_zone,price',
        'group' => 'id_group,reduction,price_display_method,show_prices,date_add,date_upd',
        'group_lang' => 'id_group,id_lang,name',
        'customer_group' => 'id_customer,id_group',
        'manufacturer' => 'id_manufacturer,name,date_add,date_upd,active',
        'manufacturer_lang' => 'id_manufacturer,id_lang,description,short_description,meta_title,meta_keywords,meta_description',
        'manufacturer_shop' => 'id_manufacturer,id_shop',
        'category' => 'id_category,id_parent,id_shop_default,level_depth,nleft,nright,active,date_add,date_upd,position,is_root_category',
        'category_lang' => 'id_category,id_shop,id_lang,name,description,additional_description,link_rewrite,meta_title,meta_keywords,meta_description',
        'category_shop' => 'id_category,id_shop,position',
        'category_product' => 'id_category,id_product,position',
        'attribute_group' => 'id_attribute_group,is_color_group,group_type,position',
        'attribute_group_lang' => 'id_attribute_group,id_lang,name,public_name',
        'attribute' => 'id_attribute,id_attribute_group,color,position',
        'attribute_lang' => 'id_attribute,id_lang,name',
        'feature' => 'id_feature,position',
        'feature_lang' => 'id_feature,id_lang,name',
        'feature_value' => 'id_feature_value,id_feature,custom',
        'feature_value_lang' => 'id_feature_value,id_lang,value',
        'feature_product' => 'id_feature,id_product,id_feature_value',
        'product' => 'id_product,id_supplier,id_manufacturer,id_category_default,id_shop_default,id_tax_rules_group,on_sale,online_only,ean13,isbn,upc,mpn,ecotax,quantity,minimal_quantity,low_stock_threshold,low_stock_alert,price,wholesale_price,unity,unit_price,unit_price_ratio,additional_shipping_cost,reference,supplier_reference,location,width,height,depth,weight,out_of_stock,additional_delivery_times,quantity_discount,customizable,uploadable_files,text_fields,active,redirect_type,id_type_redirected,available_for_order,available_date,show_condition,condition,show_price,indexed,visibility,cache_is_pack,cache_has_attachments,is_virtual,cache_default_attribute,date_add,date_upd,advanced_stock_management,pack_stock_type,state,product_type',
        'product_shop' => 'id_product,id_shop,id_category_default,id_tax_rules_group,on_sale,online_only,ecotax,minimal_quantity,low_stock_threshold,low_stock_alert,price,wholesale_price,unity,unit_price,unit_price_ratio,additional_shipping_cost,customizable,uploadable_files,text_fields,active,redirect_type,id_type_redirected,available_for_order,available_date,show_condition,condition,show_price,indexed,visibility,cache_default_attribute,advanced_stock_management,date_add,date_upd,pack_stock_type',
        'product_lang' => 'id_product,id_shop,id_lang,description,description_short,link_rewrite,meta_description,meta_keywords,meta_title,name,available_now,available_later,delivery_in_stock,delivery_out_stock',
        'product_attribute' => 'id_product_attribute,id_product,reference,supplier_reference,ean13,isbn,upc,mpn,wholesale_price,price,ecotax,weight,unit_price_impact,default_on,minimal_quantity,low_stock_threshold,low_stock_alert,available_date',
        'product_attribute_shop' => 'id_product,id_product_attribute,id_shop,wholesale_price,price,ecotax,weight,unit_price_impact,default_on,minimal_quantity,low_stock_threshold,low_stock_alert,available_date',
        'product_attribute_combination' => 'id_attribute,id_product_attribute',
        'product_attribute_image' => 'id_product_attribute,id_image',
        'stock_available' => 'id_stock_available,id_product,id_product_attribute,id_shop,id_shop_group,quantity,physical_quantity,reserved_quantity,depends_on_stock,out_of_stock,location',
        'image' => 'id_image,id_product,position,cover',
        'image_shop' => 'id_product,id_image,id_shop,cover',
        'specific_price' => 'id_specific_price,id_specific_price_rule,id_cart,id_product,id_shop,id_shop_group,id_currency,id_country,id_group,id_customer,id_product_attribute,price,from_quantity,reduction,reduction_tax,reduction_type,from,to',
        'tag' => 'id_tag,id_lang,name',
        'product_tag' => 'id_product,id_tag,id_lang',
        'accessory' => 'id_product_1,id_product_2',
        'attachment' => 'id_attachment,file,file_name,file_size,mime',
        'attachment_lang' => 'id_attachment,id_lang,name,description',
        'product_attachment' => 'id_product,id_attachment',
        'product_download' => 'id_product_download,id_product,display_filename,filename,date_add,date_expiration,nb_days_accessible,nb_downloadable,active,is_shareable',
        'product_supplier' => 'id_product_supplier,id_product,id_product_attribute,id_supplier,product_supplier_reference,product_supplier_price_te,id_currency',
        'pack' => 'id_product_pack,id_product_item,id_product_attribute_item,quantity',
        'supplier' => 'id_supplier,name,date_add,date_upd,active',
        'customer' => 'id_customer,id_shop_group,id_shop,id_gender,id_default_group,id_lang,id_risk,company,siret,ape,firstname,lastname,email,passwd,last_passwd_gen,birthday,newsletter,ip_registration_newsletter,newsletter_date_add,optin,website,outstanding_allow_amount,show_public_prices,max_payment_days,secure_key,note,active,is_guest,deleted,date_add,date_upd,reset_password_token,reset_password_validity',
        'address' => 'id_address,id_country,id_state,id_customer,id_manufacturer,id_supplier,id_warehouse,alias,company,lastname,firstname,address1,address2,postcode,city,other,phone,phone_mobile,vat_number,dni,date_add,date_upd,active,deleted',
        'orders' => 'id_order,reference,id_shop_group,id_shop,id_carrier,id_lang,id_customer,id_cart,id_currency,id_address_delivery,id_address_invoice,current_state,secure_key,payment,conversion_rate,module,recyclable,gift,gift_message,mobile_theme,total_discounts,total_discounts_tax_incl,total_discounts_tax_excl,total_paid,total_paid_tax_incl,total_paid_tax_excl,total_paid_real,total_products,total_products_wt,total_shipping,total_shipping_tax_incl,total_shipping_tax_excl,carrier_tax_rate,total_wrapping,total_wrapping_tax_incl,total_wrapping_tax_excl,round_mode,round_type,invoice_number,delivery_number,invoice_date,delivery_date,valid,date_add,date_upd,note',
        'order_detail' => 'id_order_detail,id_order,id_order_invoice,id_warehouse,id_shop,product_id,product_attribute_id,id_customization,product_name,product_quantity,product_quantity_in_stock,product_quantity_refunded,product_quantity_return,product_quantity_reinjected,product_price,reduction_percent,reduction_amount,reduction_amount_tax_incl,reduction_amount_tax_excl,group_reduction,product_quantity_discount,product_ean13,product_isbn,product_upc,product_mpn,product_reference,product_supplier_reference,product_weight,id_tax_rules_group,tax_computation_method,tax_name,tax_rate,ecotax,ecotax_tax_rate,discount_quantity_applied,download_hash,download_nb,download_deadline,total_price_tax_incl,total_price_tax_excl,unit_price_tax_incl,unit_price_tax_excl,total_shipping_price_tax_incl,total_shipping_price_tax_excl,purchase_supplier_price,original_product_price,original_wholesale_price,total_refunded_tax_excl,total_refunded_tax_incl',
        'order_detail_tax' => 'id_order_detail,id_tax,unit_amount,total_amount',
        'order_history' => 'id_order_history,id_employee,id_order,id_order_state,date_add',
        'order_payment' => 'id_order_payment,order_reference,id_currency,amount,payment_method,conversion_rate,transaction_id,card_number,card_brand,card_expiration,card_holder,date_add,id_employee',
        'order_invoice' => 'id_order_invoice,id_order,number,delivery_number,delivery_date,total_discount_tax_excl,total_discount_tax_incl,total_paid_tax_excl,total_paid_tax_incl,total_products,total_products_wt,total_shipping_tax_excl,total_shipping_tax_incl,shipping_tax_computation_method,total_wrapping_tax_excl,total_wrapping_tax_incl,shop_address,note,date_add',
        'order_slip' => 'id_order_slip,conversion_rate,id_customer,id_order,total_products_tax_excl,total_products_tax_incl,total_shipping_tax_excl,total_shipping_tax_incl,shipping_cost,amount,shipping_cost_amount,partial,order_slip_type,date_add,date_upd',
        'order_slip_detail' => 'id_order_slip,id_order_detail,product_quantity,unit_price_tax_excl,unit_price_tax_incl,total_price_tax_excl,total_price_tax_incl,amount_tax_excl,amount_tax_incl',
        'order_carrier' => 'id_order_carrier,id_order,id_carrier,id_order_invoice,weight,shipping_cost_tax_excl,shipping_cost_tax_incl,tracking_number,date_add',
        'order_cart_rule' => 'id_order_cart_rule,id_order,id_cart_rule,id_order_invoice,name,value,value_tax_excl,free_shipping,deleted',
        'order_state' => 'id_order_state,invoice,send_email,module_name,color,unremovable,hidden,logable,delivery,shipped,paid,pdf_invoice,pdf_delivery,deleted',
        'order_state_lang' => 'id_order_state,id_lang,name,template',
        'cart_rule' => 'id_cart_rule,id_customer,date_from,date_to,description,quantity,quantity_per_user,priority,partial_use,code,minimum_amount,minimum_amount_tax,minimum_amount_currency,minimum_amount_shipping,country_restriction,carrier_restriction,group_restriction,cart_rule_restriction,product_restriction,shop_restriction,free_shipping,reduction_percent,reduction_amount,reduction_tax,reduction_currency,reduction_product,reduction_exclude_special,gift_product,gift_product_attribute,highlight,active,date_add,date_upd',
        'cart_rule_lang' => 'id_cart_rule,id_lang,name',
        'cart_rule_country' => 'id_cart_rule,id_country',
        'cart_rule_group' => 'id_cart_rule,id_group',
        'cart_rule_carrier' => 'id_cart_rule,id_carrier',
        'cart_rule_combination' => 'id_cart_rule_1,id_cart_rule_2',
        'cart_rule_product_rule_group' => 'id_product_rule_group,id_cart_rule,quantity',
        'cart_rule_product_rule' => 'id_product_rule,id_product_rule_group,type',
        'cart_rule_product_rule_value' => 'id_product_rule,id_item',
        'cart_rule_shop' => 'id_cart_rule,id_shop',
        'cms' => 'id_cms,id_cms_category,position,active,indexation',
        'cms_lang' => 'id_cms,id_lang,id_shop,meta_title,head_seo_title,meta_description,meta_keywords,content,link_rewrite',
        'cms_shop' => 'id_cms,id_shop',
        'employee' => 'id_employee,id_profile,id_lang,lastname,firstname,email,passwd,last_passwd_gen,active,optin,last_connection_date',
        'profile' => 'id_profile',
        'profile_lang' => 'id_lang,id_profile,name',
        'meta' => 'id_meta,page,configurable',
        'meta_lang' => 'id_meta,id_shop,id_lang,title,description,keywords,url_rewrite',
        'module' => 'id_module,name,active,version',
        'module_shop' => 'id_module,id_shop,enable_device',
        'emailsubscription' => 'id,id_shop,id_shop_group,email,newsletter_date_add,ip_registration_newsletter,http_referer,active,id_lang',
        'product_comment' => 'id_product_comment,id_product,id_customer,id_guest,title,content,customer_name,grade,validate,deleted,date_add',
        'customer_message' => 'id_customer_message,id_customer_thread,id_employee,message,file_name,ip_address,user_agent,date_add,date_upd,private',
        'store' => 'id_store,id_country,id_state,city,postcode,latitude,longitude,phone,fax,email,active,date_add,date_upd',
        'warehouse' => 'id_warehouse,id_currency,id_address,id_employee,reference,name,management_type,deleted',
        'cart' => 'id_cart,id_shop_group,id_shop,id_carrier,delivery_option,id_lang,id_address_delivery,id_address_invoice,id_currency,id_customer,id_guest,secure_key,recyclable,gift,gift_message,mobile_theme,allow_seperated_package,date_add,date_upd,checkout_session_data',
    ];
    if ($variant === '1.7') {
        $tables['product'] = str_replace('unit_price,', '', $tables['product']);
        $tables['product_shop'] = str_replace('unit_price,', '', $tables['product_shop']);
        $tables['carrier'] .= ',id_tax_rules_group';
        $tables['orders'] .= ',shipping_number';
        $tables['category_lang'] = str_replace('additional_description,', '', $tables['category_lang']);
        $tables['product_attribute'] .= ',location,quantity';
        $tables['order_payment'] = str_replace(',id_employee', '', $tables['order_payment']);
        unset($tables['emailsubscription']);
    }
    if ($variant === '9.1') {
        $tables['cart_rule'] .= ',id_cart_rule_type,minimum_product_quantity';
        $tables['category'] .= ',id_type_redirected,redirect_type';
        $tables['feature_value'] .= ',position';
        foreach (['manufacturer_lang', 'category_lang', 'product_lang', 'cms_lang'] as $table) {
            $tables[$table] = str_replace('meta_keywords,', '', $tables[$table]);
        }
        $tables['meta_lang'] = str_replace('keywords,', '', $tables['meta_lang']);
        $tables['customer_message'] .= ',id_product';
    }

    return $tables;
}

/** El tipo de una columna: entero si es una clave o una marca, texto para todo lo demás (importes, fechas, nombres). */
function psColumnType(string $column): string
{
    $integer = '/^(id$|id_|active$|deleted$|position$|quantity$|level_depth$|nleft$|nright$|is_|show_|available_for_order$|on_sale$|online_only$|minimal_quantity$|customizable$|'
        . 'text_fields$|uploadable_files$|from_quantity$|product_quantity|valid$|newsletter$|optin$|paid$|shipped$|delivery$|logable$|invoice$|send_email$|hidden$|'
        . 'unremovable$|main$|cover$|default_on$|custom$|number$|reduction_tax$|partial$|free_shipping$|gift_product|highlight$|priority$|.*_restriction$|shipping_handling$|'
        . 'shipping_method$|range_behavior$|behavior$|contains_states$|price_display_method$|nb_downloadable$|nb_days_accessible$|physical_quantity$|reserved_quantity$|'
        . 'depends_on_stock$|out_of_stock$|private$|need_range$|shipping_external$|partial_use$|minimum_amount_tax$|minimum_amount_shipping$|reduction_product$|'
        . 'minimum_product_quantity$|cache_|indexed$|show_prices$)/';

    return preg_match($integer, $column) === 1 ? 'INTEGER' : 'TEXT';
}

/**
 * Una copia de la base de datos de PrestaShop en memoria, vacía, con el prefijo «ps_».
 */
function psMemoryDb(string $variant = '8.2'): \PDO
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    foreach (psTables($variant) as $table => $columns) {
        $defs = [];
        foreach (explode(',', $columns) as $column) {
            $defs[] = '`' . $column . '` ' . psColumnType($column);
        }
        $pdo->exec('CREATE TABLE `ps_' . $table . '` (' . implode(', ', $defs) . ')');
    }

    return $pdo;
}
