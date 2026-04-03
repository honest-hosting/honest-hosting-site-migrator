<?php
/**
 * Configuration section partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 *
 * @var array<string, mixed> $hh_view View data from AdminPage.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section">
	<h2><?php esc_html_e( 'Configuration', 'honest-hosting-site-migrator' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="hh-migrator-api-base-url"><?php esc_html_e( 'API Base URL', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<input
					type="url"
					id="hh-migrator-api-base-url"
					class="regular-text"
					value="<?php echo esc_attr( $hh_view['api_base_url'] ); ?>"
					<?php echo $hh_view['base_url_locked'] ? 'readonly="readonly"' : ''; ?>
				/>
				<?php if ( $hh_view['base_url_locked'] ) : ?>
					<p class="description">
						<?php esc_html_e( 'Set via HH_MIGRATOR_API_BASE_URL constant in wp-config.php.', 'honest-hosting-site-migrator' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="hh-migrator-import-key"><?php esc_html_e( 'Site Import Key', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<input
					type="password"
					id="hh-migrator-import-key"
					class="regular-text"
					value="<?php echo esc_attr( $hh_view['import_key'] ); ?>"
					autocomplete="off"
				/>
				<button type="button" id="hh-migrator-validate-key" class="button">
					<?php esc_html_e( 'Validate Key', 'honest-hosting-site-migrator' ); ?>
				</button>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="hh-migrator-chunk-size"><?php esc_html_e( 'Chunk Size', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="hh-migrator-chunk-size"
					class="small-text"
					value="<?php echo esc_attr( $hh_view['chunk_size'] ); ?>"
					placeholder="2 MB"
				/>
				<p class="description">
					<?php esc_html_e( 'Upload chunk size (2 MB \u2013 200 MB). Example: 2MB, 50Mb, 100mb.', 'honest-hosting-site-migrator' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="button" id="hh-migrator-save-config" class="button button-primary">
			<?php esc_html_e( 'Save Configuration', 'honest-hosting-site-migrator' ); ?>
		</button>
	</p>
</div>
