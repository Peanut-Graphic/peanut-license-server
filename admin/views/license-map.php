<?php
/**
 * License Map - Visual tree view of products, licenses, and connected sites
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

global $wpdb;

// Get all products
$products = Peanut_Update_Server::get_all_products();

// Build the tree data
$tree_data = [];

foreach ($products as $slug => $product) {
    $product_node = [
        'name' => $product['name'],
        'slug' => $slug,
        'type' => 'product',
        'licenses' => [],
        'stats' => [
            'total_licenses' => 0,
            'active_licenses' => 0,
            'total_sites' => 0,
        ],
    ];

    // Get licenses for this product (based on slug pattern in license key or meta)
    // For now, get all licenses and group by tier
    $licenses = $wpdb->get_results($wpdb->prepare("
        SELECT l.*,
            (SELECT COUNT(*) FROM {$wpdb->prefix}peanut_activations WHERE license_id = l.id AND is_active = 1) as active_sites
        FROM {$wpdb->prefix}peanut_licenses l
        WHERE l.status != 'revoked'
        ORDER BY l.tier, l.created_at DESC
    "));
    Peanut_License_Key_Vault::reveal_rows($licenses);

    $tiers = ['free' => [], 'pro' => [], 'agency' => []];

    foreach ($licenses as $license) {
        $tier = $license->tier ?? 'pro';
        if (!isset($tiers[$tier])) {
            $tiers[$tier] = [];
        }

        // Get activations for this license
        $activations = $wpdb->get_results($wpdb->prepare("
            SELECT site_url, site_name, is_active, plugin_version, last_checked
            FROM {$wpdb->prefix}peanut_activations
            WHERE license_id = %d AND is_active = 1
            ORDER BY activated_at DESC
        ", $license->id));

        $tiers[$tier][] = [
            'id' => $license->id,
            'key' => $license->license_key,
            'customer_email' => $license->customer_email,
            'customer_name' => $license->customer_name,
            'status' => $license->status,
            'max_activations' => $license->max_activations,
            'active_sites' => $license->active_sites,
            'expires_at' => $license->expires_at,
            'activations' => $activations,
        ];

        $product_node['stats']['total_licenses']++;
        if ($license->status === 'active') {
            $product_node['stats']['active_licenses']++;
        }
        $product_node['stats']['total_sites'] += $license->active_sites;
    }

    $product_node['licenses'] = $tiers;
    $tree_data[$slug] = $product_node;
}
?>

<div class="wrap peanut-license-map">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-networking" style="font-size: 28px; width: 28px; height: 28px; margin-right: 10px;"></span>
        <?php esc_html_e('License Map', 'peanut-license-server'); ?>
    </h1>
    <hr class="wp-header-end">

    <!-- Info Card -->
    <?php if (!get_user_meta(get_current_user_id(), 'peanut_dismissed_map_info', true)) : ?>
    <div class="peanut-info-card" data-card-id="map_info">
        <button type="button" class="peanut-info-card-dismiss" aria-label="<?php esc_attr_e('Dismiss', 'peanut-license-server'); ?>">&times;</button>
        <div class="peanut-info-card-icon">
            <span class="dashicons dashicons-networking"></span>
        </div>
        <div class="peanut-info-card-content">
            <h3><?php esc_html_e('License Map Overview', 'peanut-license-server'); ?></h3>
            <p><?php esc_html_e('Visual tree view showing how your products, licenses, and sites connect:', 'peanut-license-server'); ?></p>
            <ul>
                <li><?php esc_html_e('Click product names to expand/collapse license tiers', 'peanut-license-server'); ?></li>
                <li><?php esc_html_e('Click license keys to see connected sites', 'peanut-license-server'); ?></li>
                <li><?php esc_html_e('Color coding: Free (gray), Pro (blue), Agency (purple)', 'peanut-license-server'); ?></li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Search/Filter -->
    <div class="peanut-map-controls">
        <input type="search" id="peanut-map-search" placeholder="<?php esc_attr_e('Search licenses, customers, or sites...', 'peanut-license-server'); ?>" class="regular-text">
        <select id="peanut-map-filter-tier">
            <option value=""><?php esc_html_e('All Tiers', 'peanut-license-server'); ?></option>
            <option value="free"><?php esc_html_e('Free', 'peanut-license-server'); ?></option>
            <option value="pro"><?php esc_html_e('Pro', 'peanut-license-server'); ?></option>
            <option value="agency"><?php esc_html_e('Agency', 'peanut-license-server'); ?></option>
        </select>
        <button type="button" id="peanut-map-expand-all" class="button"><?php esc_html_e('Expand All', 'peanut-license-server'); ?></button>
        <button type="button" id="peanut-map-collapse-all" class="button"><?php esc_html_e('Collapse All', 'peanut-license-server'); ?></button>
    </div>

    <!-- Tree View -->
    <div class="peanut-tree-container">
        <?php foreach ($tree_data as $slug => $product) : ?>
        <div class="peanut-tree-product" data-product="<?php echo esc_attr($slug); ?>">
            <div class="peanut-tree-node product-node is-expanded">
                <span class="peanut-tree-toggle">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </span>
                <span class="peanut-tree-icon">
                    <span class="dashicons dashicons-admin-plugins"></span>
                </span>
                <span class="peanut-tree-label"><?php echo esc_html($product['name']); ?></span>
                <span class="peanut-tree-stats">
                    <span class="stat"><?php echo esc_html($product['stats']['total_licenses']); ?> <?php esc_html_e('licenses', 'peanut-license-server'); ?></span>
                    <span class="stat"><?php echo esc_html($product['stats']['total_sites']); ?> <?php esc_html_e('sites', 'peanut-license-server'); ?></span>
                </span>
            </div>
            <div class="peanut-tree-children">
                <?php foreach (['agency' => __('Agency', 'peanut-license-server'), 'pro' => __('Pro', 'peanut-license-server'), 'free' => __('Free', 'peanut-license-server')] as $tier_key => $tier_label) : ?>
                    <?php if (!empty($product['licenses'][$tier_key])) : ?>
                    <div class="peanut-tree-tier tier-<?php echo esc_attr($tier_key); ?>" data-tier="<?php echo esc_attr($tier_key); ?>">
                        <div class="peanut-tree-node tier-node is-collapsed">
                            <span class="peanut-tree-toggle">
                                <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </span>
                            <span class="peanut-tree-icon tier-badge tier-<?php echo esc_attr($tier_key); ?>">
                                <?php echo esc_html(strtoupper(substr($tier_key, 0, 1))); ?>
                            </span>
                            <span class="peanut-tree-label"><?php echo esc_html($tier_label); ?></span>
                            <span class="peanut-tree-stats">
                                <span class="stat"><?php echo count($product['licenses'][$tier_key]); ?> <?php esc_html_e('licenses', 'peanut-license-server'); ?></span>
                            </span>
                        </div>
                        <div class="peanut-tree-children" style="display: none;">
                            <?php foreach ($product['licenses'][$tier_key] as $license) : ?>
                            <div class="peanut-tree-license" data-license-id="<?php echo esc_attr($license['id']); ?>" data-email="<?php echo esc_attr($license['customer_email']); ?>">
                                <div class="peanut-tree-node license-node <?php echo $license['active_sites'] > 0 ? 'is-collapsed' : ''; ?>">
                                    <?php if ($license['active_sites'] > 0) : ?>
                                    <span class="peanut-tree-toggle">
                                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                                    </span>
                                    <?php else : ?>
                                    <span class="peanut-tree-toggle no-children"></span>
                                    <?php endif; ?>
                                    <span class="peanut-tree-icon status-<?php echo esc_attr($license['status']); ?>">
                                        <span class="dashicons dashicons-admin-network"></span>
                                    </span>
                                    <span class="peanut-tree-label">
                                        <code><?php echo esc_html(substr($license['key'], 0, 8) . '...' . substr($license['key'], -4)); ?></code>
                                        <span class="customer-name"><?php echo esc_html($license['customer_name'] ?: $license['customer_email']); ?></span>
                                    </span>
                                    <span class="peanut-tree-stats">
                                        <span class="stat sites"><?php echo esc_html($license['active_sites']); ?>/<?php echo esc_html($license['max_activations']); ?></span>
                                        <?php if ($license['status'] !== 'active') : ?>
                                        <span class="stat status status-<?php echo esc_attr($license['status']); ?>"><?php echo esc_html(ucfirst($license['status'])); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($license['active_sites'] > 0) : ?>
                                <div class="peanut-tree-children" style="display: none;">
                                    <?php foreach ($license['activations'] as $activation) : ?>
                                    <div class="peanut-tree-site">
                                        <div class="peanut-tree-node site-node">
                                            <span class="peanut-tree-toggle no-children"></span>
                                            <span class="peanut-tree-icon">
                                                <span class="dashicons dashicons-admin-site"></span>
                                            </span>
                                            <span class="peanut-tree-label">
                                                <a href="<?php echo esc_url($activation->site_url); ?>" target="_blank">
                                                    <?php echo esc_html($activation->site_name ?: parse_url($activation->site_url, PHP_URL_HOST)); ?>
                                                </a>
                                            </span>
                                            <span class="peanut-tree-stats">
                                                <?php if ($activation->plugin_version) : ?>
                                                <span class="stat version">v<?php echo esc_html($activation->plugin_version); ?></span>
                                                <?php endif; ?>
                                                <?php if ($activation->last_checked) : ?>
                                                <span class="stat last-seen"><?php echo esc_html(human_time_diff(strtotime($activation->last_checked), current_time('timestamp'))); ?> <?php esc_html_e('ago', 'peanut-license-server'); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($tree_data) || empty(array_filter(array_column($tree_data, 'licenses')))) : ?>
        <div class="peanut-tree-empty">
            <span class="dashicons dashicons-info"></span>
            <p><?php esc_html_e('No licenses found. Create your first license to see it here.', 'peanut-license-server'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=peanut-licenses-add')); ?>" class="button button-primary">
                <?php esc_html_e('Add License', 'peanut-license-server'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
