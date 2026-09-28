<?php
/**
 * Site Health Dashboard
 *
 * Shows the health status of all activated sites across all licenses.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

global $wpdb;

// Get filter parameters.
$filter_status = isset($_GET['health_status']) ? sanitize_text_field($_GET['health_status']) : '';
$filter_product = isset($_GET['product']) ? sanitize_text_field($_GET['product']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Build query.
$where = ['a.is_active = 1'];
$params = [];

if ($filter_status && in_array($filter_status, ['healthy', 'warning', 'critical', 'offline'])) {
    $where[] = 'a.health_status = %s';
    $params[] = $filter_status;
}

if ($search) {
    $where[] = '(a.site_url LIKE %s OR a.site_name LIKE %s OR l.customer_email LIKE %s)';
    $search_param = '%' . $wpdb->esc_like($search) . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where);

// Get health statistics.
$stats = $wpdb->get_row("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN health_status = 'healthy' THEN 1 ELSE 0 END) as healthy,
        SUM(CASE WHEN health_status = 'warning' THEN 1 ELSE 0 END) as warning,
        SUM(CASE WHEN health_status = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN health_status = 'offline' OR last_checked < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as offline
    FROM {$wpdb->prefix}peanut_activations
    WHERE is_active = 1
");

// Get activations with license info.
$query = "
    SELECT
        a.*,
        l.license_key,
        l.customer_email,
        l.customer_name,
        l.tier,
        l.status as license_status,
        l.max_activations,
        (SELECT COUNT(*) FROM {$wpdb->prefix}peanut_activations WHERE license_id = l.id AND is_active = 1) as activation_count
    FROM {$wpdb->prefix}peanut_activations a
    JOIN {$wpdb->prefix}peanut_licenses l ON a.license_id = l.id
    WHERE {$where_sql}
    ORDER BY
        CASE a.health_status
            WHEN 'critical' THEN 1
            WHEN 'warning' THEN 2
            WHEN 'offline' THEN 3
            ELSE 4
        END,
        a.last_checked DESC
    LIMIT 100
";

if (!empty($params)) {
    $sites = Peanut_License_Key_Vault::reveal_rows($wpdb->get_results($wpdb->prepare($query, $params)));
} else {
    $sites = Peanut_License_Key_Vault::reveal_rows($wpdb->get_results($query));
}

// Check for sites that haven't reported in 7 days.
$stale_count = $wpdb->get_var("
    SELECT COUNT(*)
    FROM {$wpdb->prefix}peanut_activations
    WHERE is_active = 1
    AND (last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL 7 DAY))
");
?>

<div class="wrap peanut-site-health">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-heart" style="font-size: 28px; width: 28px; height: 28px; margin-right: 10px;"></span>
        <?php esc_html_e('Site Health Dashboard', 'peanut-license-server'); ?>
    </h1>

    <!-- Info Card -->
    <?php if (!get_user_meta(get_current_user_id(), 'peanut_dismissed_health_info', true)) : ?>
    <div class="peanut-info-card" data-card-id="health_info" style="margin-top: 20px;">
        <button type="button" class="peanut-info-card-dismiss" aria-label="<?php esc_attr_e('Dismiss', 'peanut-license-server'); ?>">&times;</button>
        <div class="peanut-info-card-icon">
            <span class="dashicons dashicons-heart"></span>
        </div>
        <div class="peanut-info-card-content">
            <h3><?php esc_html_e('Site Health Monitoring', 'peanut-license-server'); ?></h3>
            <p><?php esc_html_e('Monitor the health of all licensed sites in real-time:', 'peanut-license-server'); ?></p>
            <ul>
                <li><strong style="color: #22c55e;"><?php esc_html_e('Healthy:', 'peanut-license-server'); ?></strong> <?php esc_html_e('Site is running smoothly', 'peanut-license-server'); ?></li>
                <li><strong style="color: #f59e0b;"><?php esc_html_e('Warning:', 'peanut-license-server'); ?></strong> <?php esc_html_e('Minor issues detected (outdated versions, etc.)', 'peanut-license-server'); ?></li>
                <li><strong style="color: #ef4444;"><?php esc_html_e('Critical:', 'peanut-license-server'); ?></strong> <?php esc_html_e('Serious problems that need attention', 'peanut-license-server'); ?></li>
                <li><strong style="color: #6b7280;"><?php esc_html_e('Offline:', 'peanut-license-server'); ?></strong> <?php esc_html_e('No health report in 7+ days', 'peanut-license-server'); ?></li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Health Status Cards -->
    <div class="peanut-health-cards" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin: 24px 0;">
        <div class="peanut-card" style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 32px; font-weight: 700; color: #111827;"><?php echo esc_html($stats->total ?? 0); ?></div>
            <div style="color: #6b7280; font-size: 14px;"><?php esc_html_e('Total Active Sites', 'peanut-license-server'); ?></div>
        </div>
        <a href="?page=peanut-site-health&health_status=healthy" class="peanut-card" style="background: #f0fdf4; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-decoration: none; border: 2px solid <?php echo $filter_status === 'healthy' ? '#22c55e' : 'transparent'; ?>;">
            <div style="font-size: 32px; font-weight: 700; color: #22c55e;"><?php echo esc_html($stats->healthy ?? 0); ?></div>
            <div style="color: #166534; font-size: 14px;"><?php esc_html_e('Healthy', 'peanut-license-server'); ?></div>
        </a>
        <a href="?page=peanut-site-health&health_status=warning" class="peanut-card" style="background: #fffbeb; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-decoration: none; border: 2px solid <?php echo $filter_status === 'warning' ? '#f59e0b' : 'transparent'; ?>;">
            <div style="font-size: 32px; font-weight: 700; color: #f59e0b;"><?php echo esc_html($stats->warning ?? 0); ?></div>
            <div style="color: #92400e; font-size: 14px;"><?php esc_html_e('Warning', 'peanut-license-server'); ?></div>
        </a>
        <a href="?page=peanut-site-health&health_status=critical" class="peanut-card" style="background: #fef2f2; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-decoration: none; border: 2px solid <?php echo $filter_status === 'critical' ? '#ef4444' : 'transparent'; ?>;">
            <div style="font-size: 32px; font-weight: 700; color: #ef4444;"><?php echo esc_html($stats->critical ?? 0); ?></div>
            <div style="color: #991b1b; font-size: 14px;"><?php esc_html_e('Critical', 'peanut-license-server'); ?></div>
        </a>
        <a href="?page=peanut-site-health&health_status=offline" class="peanut-card" style="background: #f3f4f6; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-decoration: none; border: 2px solid <?php echo $filter_status === 'offline' ? '#6b7280' : 'transparent'; ?>;">
            <div style="font-size: 32px; font-weight: 700; color: #6b7280;"><?php echo esc_html($stale_count ?? 0); ?></div>
            <div style="color: #4b5563; font-size: 14px;"><?php esc_html_e('Offline / No Report', 'peanut-license-server'); ?></div>
        </a>
    </div>

    <?php if ($stale_count > 0) : ?>
    <div class="notice notice-warning" style="margin: 16px 0;">
        <p>
            <strong><?php esc_html_e('Attention:', 'peanut-license-server'); ?></strong>
            <?php printf(
                esc_html__('%d sites have not reported their health status in over 7 days. They may need attention.', 'peanut-license-server'),
                $stale_count
            ); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Search & Filters -->
    <div class="tablenav top" style="margin-bottom: 16px;">
        <form method="get" style="display: flex; gap: 12px; align-items: center;">
            <input type="hidden" name="page" value="peanut-site-health">

            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search sites...', 'peanut-license-server'); ?>" class="regular-text">

            <select name="health_status">
                <option value=""><?php esc_html_e('All Statuses', 'peanut-license-server'); ?></option>
                <option value="healthy" <?php selected($filter_status, 'healthy'); ?>><?php esc_html_e('Healthy', 'peanut-license-server'); ?></option>
                <option value="warning" <?php selected($filter_status, 'warning'); ?>><?php esc_html_e('Warning', 'peanut-license-server'); ?></option>
                <option value="critical" <?php selected($filter_status, 'critical'); ?>><?php esc_html_e('Critical', 'peanut-license-server'); ?></option>
                <option value="offline" <?php selected($filter_status, 'offline'); ?>><?php esc_html_e('Offline', 'peanut-license-server'); ?></option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'peanut-license-server'); ?></button>

            <?php if ($filter_status || $search) : ?>
                <a href="?page=peanut-site-health" class="button"><?php esc_html_e('Clear', 'peanut-license-server'); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Sites Table -->
    <table class="wp-list-table widefat fixed striped" style="background: #fff; border-radius: 8px; overflow: hidden;">
        <thead>
            <tr>
                <th style="width: 40px;"><?php esc_html_e('Status', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('Site', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('License', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('Plugin', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('WordPress', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('PHP', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('Sites Used', 'peanut-license-server'); ?></th>
                <th><?php esc_html_e('Last Report', 'peanut-license-server'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sites)) : ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px;">
                        <?php esc_html_e('No sites found matching your criteria.', 'peanut-license-server'); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($sites as $site) :
                    $status_color = match($site->health_status) {
                        'healthy' => '#22c55e',
                        'warning' => '#f59e0b',
                        'critical' => '#ef4444',
                        default => '#6b7280',
                    };

                    $is_stale = !$site->last_checked || strtotime($site->last_checked) < strtotime('-7 days');
                    if ($is_stale) {
                        $status_color = '#6b7280';
                        $site->health_status = 'offline';
                    }

                    $at_limit = $site->activation_count >= $site->max_activations;
                ?>
                <tr>
                    <td>
                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo esc_attr($status_color); ?>; box-shadow: 0 0 0 3px <?php echo esc_attr($status_color); ?>33;" title="<?php echo esc_attr(ucfirst($site->health_status)); ?>"></span>
                    </td>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url($site->site_url); ?>" target="_blank" style="text-decoration: none;">
                                <?php echo esc_html($site->site_name ?: parse_url($site->site_url, PHP_URL_HOST)); ?>
                            </a>
                        </strong>
                        <br>
                        <small style="color: #6b7280;"><?php echo esc_html($site->site_url); ?></small>
                        <?php if ($site->is_multisite) : ?>
                            <span style="background: #dbeafe; color: #1e40af; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">Multisite</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code style="font-size: 11px; background: #f3f4f6; padding: 2px 6px; border-radius: 4px;">
                            <?php echo esc_html(substr($site->license_key, 0, 4) . '....' . substr($site->license_key, -4)); ?>
                        </code>
                        <br>
                        <small style="color: #6b7280;"><?php echo esc_html($site->customer_email); ?></small>
                        <span style="background: <?php echo $site->tier === 'agency' ? '#7c3aed' : ($site->tier === 'pro' ? '#2563eb' : '#6b7280'); ?>; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">
                            <?php echo esc_html(ucfirst($site->tier)); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($site->plugin_version) : ?>
                            <span style="font-family: monospace;"><?php echo esc_html($site->plugin_version); ?></span>
                        <?php else : ?>
                            <span style="color: #9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($site->wp_version) : ?>
                            <span style="font-family: monospace;"><?php echo esc_html($site->wp_version); ?></span>
                        <?php else : ?>
                            <span style="color: #9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($site->php_version) : ?>
                            <span style="font-family: monospace;"><?php echo esc_html($site->php_version); ?></span>
                        <?php else : ?>
                            <span style="color: #9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="<?php echo $at_limit ? 'color: #ef4444; font-weight: 600;' : ''; ?>">
                            <?php echo esc_html($site->activation_count); ?> / <?php echo esc_html($site->max_activations); ?>
                        </span>
                        <?php if ($at_limit) : ?>
                            <span style="background: #fef2f2; color: #991b1b; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">At Limit</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($site->last_checked) : ?>
                            <span title="<?php echo esc_attr($site->last_checked); ?>">
                                <?php echo esc_html(human_time_diff(strtotime($site->last_checked), current_time('timestamp'))); ?> ago
                            </span>
                            <?php if ($is_stale) : ?>
                                <br><small style="color: #ef4444;"><?php esc_html_e('Needs attention', 'peanut-license-server'); ?></small>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color: #9ca3af;"><?php esc_html_e('Never', 'peanut-license-server'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($site->health_errors) :
                    $errors = json_decode($site->health_errors, true);
                    if (!empty($errors)) :
                ?>
                <tr style="background: #fef2f2;">
                    <td></td>
                    <td colspan="7" style="padding: 8px 16px;">
                        <strong style="color: #991b1b;"><?php esc_html_e('Errors:', 'peanut-license-server'); ?></strong>
                        <ul style="margin: 4px 0 0 16px; color: #991b1b; font-size: 13px;">
                            <?php foreach ($errors as $error) : ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
                <?php endif; endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Info Box -->
    <div style="margin-top: 24px; padding: 20px; background: #eff6ff; border-radius: 8px; border: 1px solid #bfdbfe;">
        <h3 style="margin-top: 0; color: #1e40af;">
            <span class="dashicons dashicons-info" style="margin-right: 8px;"></span>
            <?php esc_html_e('About Site Health Monitoring', 'peanut-license-server'); ?>
        </h3>
        <p style="color: #1e40af; margin-bottom: 8px;">
            <?php esc_html_e('Licensed sites automatically report their health status daily. This helps you:', 'peanut-license-server'); ?>
        </p>
        <ul style="margin-left: 20px; color: #1e40af;">
            <li><?php esc_html_e('Monitor which sites are running your plugins', 'peanut-license-server'); ?></li>
            <li><?php esc_html_e('Identify sites with outdated plugin versions', 'peanut-license-server'); ?></li>
            <li><?php esc_html_e('Detect compatibility issues (PHP/WP version)', 'peanut-license-server'); ?></li>
            <li><?php esc_html_e('Track license usage across all customers', 'peanut-license-server'); ?></li>
            <li><?php esc_html_e('Proactively reach out when sites have problems', 'peanut-license-server'); ?></li>
        </ul>
    </div>
</div>
