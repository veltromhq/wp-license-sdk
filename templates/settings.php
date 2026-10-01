<?php
/**
 * The licence screen. Rendered by Veltrom\License\Admin\SettingsPage::render().
 *
 * @var \Veltrom\License\Admin\SettingsView $view
 * @var \Veltrom\License\Config $config
 * @var mixed $notice
 * @var string $action
 * @var string $postUrl
 * @var callable(string): string $t
 */

defined('ABSPATH') || exit;

$noticeClass = ['success' => 'notice-success', 'warning' => 'notice-warning', 'error' => 'notice-error', 'info' => 'notice-info'];
?>
<div class="wrap">
    <h1><?php echo esc_html($config->pageTitle); ?></h1>

    <?php if (is_array($notice) && isset($notice['message'])) : ?>
        <div class="notice <?php echo ! empty($notice['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
    <?php endif; ?>

    <div class="notice inline <?php echo esc_attr($noticeClass[$view->tone] ?? 'notice-info'); ?>">
        <p><strong><?php echo esc_html($view->headline); ?></strong></p>
        <?php if ($view->explanation !== null) : ?>
            <p><?php echo esc_html($view->explanation); ?></p>
        <?php endif; ?>
        <?php if ($view->primaryLinkUrl !== null && $view->primaryLinkLabel !== null) : ?>
            <p><a class="button button-primary" href="<?php echo esc_url($view->primaryLinkUrl); ?>" target="_blank" rel="noopener"><?php echo esc_html($view->primaryLinkLabel); ?></a></p>
        <?php endif; ?>
    </div>

    <?php if ($view->details !== []) : ?>
        <table class="form-table" role="presentation">
            <?php foreach ($view->details as $detail) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html($detail['label']); ?></th>
                    <td><?php echo esc_html($detail['value']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if ($view->quietNote !== null) : ?>
        <p class="description"><?php echo esc_html($view->quietNote); ?></p>
    <?php endif; ?>

    <?php if ($view->showKeyInput) : ?>
        <form method="post" action="<?php echo esc_url($postUrl); ?>">
            <?php wp_nonce_field($action); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="operation" value="activate">
            <p>
                <label for="veltrom-license-key"><?php echo esc_html($t('Licence key')); ?></label><br>
                <input id="veltrom-license-key" name="license_key" type="text" class="regular-text code" autocomplete="off" spellcheck="false" required>
            </p>
            <?php submit_button($t('Activate')); ?>
        </form>
    <?php endif; ?>

    <?php if ($view->showRefresh || $view->showDeactivate) : ?>
        <p>
            <?php if ($view->showRefresh) : ?>
                <form method="post" action="<?php echo esc_url($postUrl); ?>" style="display:inline">
                    <?php wp_nonce_field($action); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                    <input type="hidden" name="operation" value="refresh">
                    <?php submit_button($t('Check now'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <?php if ($view->showDeactivate) : ?>
                <form method="post" action="<?php echo esc_url($postUrl); ?>" style="display:inline">
                    <?php wp_nonce_field($action); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                    <input type="hidden" name="operation" value="deactivate">
                    <?php submit_button($t('Deactivate on this site'), 'delete', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>
