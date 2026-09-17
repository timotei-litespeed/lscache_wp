/**
 * ESI settings panel for block widgets (Widgets screen and Customizer).
 * Saved on the widget block as `metadata[litespeed-cache-conf]`, same shape as a classic widget instance.
 *
 * @since 7.9.2
 */
(function (wp) {
	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const KEY = 'litespeed-cache-conf';

	const withEsiPanel = wp.compose.createHigherOrderComponent(
		BlockEdit => props => {
			// Widget = top-level block (of this block or its ancestors): editor root in the Customizer, direct child of a widget area on the Widgets screen.
			const widgetId = wp.data.useSelect(
				select => {
					const ids = [...select('core/block-editor').getBlockParents(props.clientId), props.clientId];
					return 'core/widget-area' === select('core/block-editor').getBlockName(ids[0]) ? ids[1] : ids[0];
				},
				[props.clientId],
			);
			const widgetName = wp.data.useSelect(select => widgetId && select('core/block-editor').getBlockName(widgetId), [widgetId]);
			const widgetMeta = wp.data.useSelect(select => widgetId && (select('core/block-editor').getBlockAttributes(widgetId) || {}).metadata, [widgetId]);
			const { updateBlockAttributes } = wp.data.useDispatch('core/block-editor');
			const type = widgetName && wp.blocks.getBlockType(widgetName);
			// `metadata` exists on all blocks since WP 6.5.
			if (!props.isSelected || !widgetId || 'core/legacy-widget' === widgetName || !type || !type.attributes.metadata) {
				return el(BlockEdit, props);
			}

			const metadata = widgetMeta || {};
			const options = metadata[KEY] || { widget_esi_enable: 0, widget_ttl: 28800 };
			const save = (esi, ttl) => {
				const next = { ...metadata, [KEY]: { widget_esi_enable: parseInt(esi, 10), widget_ttl: parseInt(ttl, 10) || 0 } };
				if (!next[KEY].widget_esi_enable) {
					delete next[KEY];
				}
				updateBlockAttributes(widgetId, { metadata: next });
			};

			return el(
				wp.element.Fragment,
				null,
				el(BlockEdit, props),
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: __('LiteSpeed Cache', 'litespeed-cache'), initialOpen: false },
						el(wp.components.RadioControl, {
							label: __('Enable ESI', 'litespeed-cache'),
							selected: String(options.widget_esi_enable),
							options: [
								{ label: __('Public', 'litespeed-cache'), value: '1' },
								{ label: __('Private', 'litespeed-cache'), value: '2' },
								{ label: __('Disable', 'litespeed-cache'), value: '0' },
							],
							onChange: value => save(value, options.widget_ttl),
						}),
						el(wp.components.TextControl, {
							label: __('Widget Cache TTL', 'litespeed-cache'),
							help: __('Recommended value: 28800 seconds (8 hours).', 'litespeed-cache') + ' ' + __('A TTL of 0 indicates do not cache.', 'litespeed-cache'),
							type: 'number',
							value: String(options.widget_ttl),
							disabled: !options.widget_esi_enable,
							onChange: value => save(options.widget_esi_enable, value),
						}),
					),
				),
			);
		},
		'withLiteSpeedEsiPanel',
	);

	wp.hooks.addFilter('editor.BlockEdit', 'litespeed-cache/esi-block-widget', withEsiPanel);
})(window.wp);
