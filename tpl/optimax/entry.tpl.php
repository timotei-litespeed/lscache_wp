<?php
/**
 * LiteSpeed Cache OptimaX
 *
 * Manages the OptimaX interface for LiteSpeed Cache.
 *
 * @package LiteSpeed
 * @since 8.0
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit;

$menu_list = array(
    'summary'  => esc_html__( 'OptimaX Summary', 'litespeed-cache' ),
    'settings' => esc_html__( 'OptimaX Settings', 'litespeed-cache' ),
);

if ( is_network_admin() ) {
    $menu_list = array(
        'network_settings' => esc_html__( 'OptimaX Settings', 'litespeed-cache' ),
    );
}

?>

<div class="wrap">
    <h1 class="litespeed-h1">
        <?php esc_html_e( 'LiteSpeed Cache OptimaX', 'litespeed-cache' ); ?>
    </h1>
    <span class="litespeed-desc">
        v<?php echo esc_html( Core::VER ); ?>
    </span>
    <hr class="wp-header-end">
</div>

<div class="litespeed-wrap">
    <h2 class="litespeed-header nav-tab-wrapper">
        <?php GUI::display_tab_list( $menu_list ); ?>
    </h2>

    <div class="litespeed-body">
        <?php if ( ! is_network_admin() && $this->conf( Base::O_OPTIMAX ) && ! Optimax::nextgen_on() ) : ?>
            <div class="litespeed-callout notice notice-warning inline">
                <h4><?php esc_html_e( 'OptimaX does not optimize images', 'litespeed-cache' ); ?></h4>
                <p>
                    <?php
                    printf(
                        /* translators: %s: link to the Next-Gen Image Format setting in Image Optimization */
                        esc_html__( 'Next-Gen Image Format is off, so OptimaX does not optimize images. Turn it on in %s to get WebP/AVIF images in OptimaX pages.', 'litespeed-cache' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=litespeed-img_optm#settings' ) ) . '">' . esc_html__( 'Image Optimization settings', 'litespeed-cache' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <?php
        foreach ( $menu_list as $menu_key => $val ) {
            GUI::display_tab_layout( $menu_list, $menu_key );
            require LSCWP_DIR . 'tpl/optimax/' . $menu_key . '.tpl.php';
            echo '</div>';
        }
        ?>
    </div>

</div>