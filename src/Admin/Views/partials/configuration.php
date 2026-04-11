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
				<label for="hh-migrator-chunk-size"><?php esc_html_e( 'Chunk Size', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="hh-migrator-chunk-size"
					class="regular-text"
					value="<?php echo esc_attr( $hh_view['chunk_size'] ); ?>"
					placeholder="10 MB"
				/>
				<p class="description">
					<?php esc_html_e( 'Upload chunk size (5 MB – 20 MB). Default: 10 MB. On shared hosting with busy sites or minimal resources, we recommend 5 MB.', 'honest-hosting-site-migrator' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="hh-migrator-compression"><?php esc_html_e( 'Compression', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<select id="hh-migrator-compression">
					<option value="auto" <?php selected( $hh_view['compression'], 'auto' ); ?>><?php esc_html_e( 'Auto', 'honest-hosting-site-migrator' ); ?></option>
					<option value="none" <?php selected( $hh_view['compression'], 'none' ); ?>><?php esc_html_e( 'None', 'honest-hosting-site-migrator' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Auto uses gzip compression when available. Select None on shared hosting with minimal resources to reduce CPU usage during uploads.', 'honest-hosting-site-migrator' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="hh-migrator-import-key"><?php esc_html_e( 'Destination Site Import Key', 'honest-hosting-site-migrator' ); ?></label>
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
		<tr id="hh-migrator-dest-name-row" <?php echo empty( $hh_view['destination_name'] ) ? 'style="display: none;"' : ''; ?>>
			<th scope="row"><?php esc_html_e( 'Destination Site', 'honest-hosting-site-migrator' ); ?></th>
			<td>
				<strong id="hh-migrator-dest-name"><?php echo esc_html( $hh_view['destination_name'] ); ?></strong>
			</td>
		</tr>
		<tr id="hh-migrator-dest-url-row" <?php echo empty( $hh_view['destination_url'] ) ? 'style="display: none;"' : ''; ?>>
			<th scope="row"><?php esc_html_e( 'Destination Site URL', 'honest-hosting-site-migrator' ); ?></th>
			<td>
				<a id="hh-migrator-dest-url-link" href="<?php echo esc_url( $hh_view['destination_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $hh_view['destination_url'] ); ?></a>
				<button type="button" id="hh-migrator-dest-url-copy" class="button-link" title="<?php esc_attr_e( 'Copy URL', 'honest-hosting-site-migrator' ); ?>" style="margin-left: 4px; vertical-align: middle;">
					<span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px;"></span>
				</button>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="button" id="hh-migrator-save-config" class="button button-primary">
			<?php esc_html_e( 'Save Configuration', 'honest-hosting-site-migrator' ); ?>
		</button>
	</p>

</div>
