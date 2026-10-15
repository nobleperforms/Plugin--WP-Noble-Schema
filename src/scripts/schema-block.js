(function (wp) {
  const { InspectorControls } = wp.blockEditor;
  const { getBlockType } = wp.blocks;
  const { PanelBody, TextControl, TextareaControl, Button } = wp.components;
  const { createElement: el, Fragment } = wp.element;
  const { addFilter } = wp.hooks;
  const { __ } = wp.i18n;

  const blockData = window.nsSchemaBlockData || { blocks: {} };
  const mappingsByBlock = blockData.blocks && typeof blockData.blocks === 'object'
    ? blockData.blocks
    : {};
  const valuesAttribute = 'nobleSchemaManual';

  function mappingsForBlock(blockName) {
    return Array.isArray(mappingsByBlock[blockName]) ? mappingsByBlock[blockName] : [];
  }

  function manualValue(attributes, key, fallback) {
    const values = attributes[valuesAttribute] || {};
    return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : fallback;
  }

  function setManualValue(setAttributes, attributes, key, value) {
    setAttributes({
      [valuesAttribute]: {
        ...(attributes[valuesAttribute] || {}),
        [key]: value,
      },
    });
  }

  function QuestionArrayField({ attributes, setAttributes, field }) {
    const items = manualValue(attributes, field.key, [{ question: '', answer: '' }]);
    const rows = Array.isArray(items) && items.length ? items : [{ question: '', answer: '' }];

    return el(
      'div',
      { className: 'ns-schema-question-array', style: { marginBottom: '1em' } },
      el('strong', null, field.label, field.required ? ' *' : ''),
      field.help
        ? el('p', { className: 'components-base-control__help' }, field.help)
        : null,
      rows.map((item, index) =>
        el(
          'div',
          {
            key: index,
            style: {
              marginBottom: '0.75em',
              padding: '0.75em',
              border: '1px solid #dcdcde',
              background: '#fff',
            },
          },
          el(TextControl, {
            label: __('Question', 'noble-schema'),
            value: item.question || '',
            onChange: (question) => {
              const next = rows.map((row, rowIndex) =>
                rowIndex === index ? { ...row, question } : row
              );
              setManualValue(setAttributes, attributes, field.key, next);
            },
          }),
          el(TextareaControl, {
            label: __('Answer', 'noble-schema'),
            value: item.answer || '',
            onChange: (answer) => {
              const next = rows.map((row, rowIndex) =>
                rowIndex === index ? { ...row, answer } : row
              );
              setManualValue(setAttributes, attributes, field.key, next);
            },
          }),
          rows.length > 1
            ? el(
                Button,
                {
                  variant: 'link',
                  isDestructive: true,
                  onClick: () => {
                    setManualValue(
                      setAttributes,
                      attributes,
                      field.key,
                      rows.filter((_, rowIndex) => rowIndex !== index)
                    );
                  },
                },
                __('Remove question', 'noble-schema')
              )
            : null
        )
      ),
      el(
        Button,
        {
          variant: 'secondary',
          onClick: () => {
            setManualValue(setAttributes, attributes, field.key, [
              ...rows,
              { question: '', answer: '' },
            ]);
          },
        },
        __('Add question', 'noble-schema')
      )
    );
  }

  function ManualField({ attributes, setAttributes, field }) {
    const valueType = field.value_type || 'text';

    if (valueType === 'question_array') {
      return el(QuestionArrayField, { attributes, setAttributes, field });
    }

    if (['geo', 'postal_address', 'place', 'person'].includes(valueType)
      || valueType.endsWith('_array')) {
      const raw = manualValue(attributes, field.key, '');
      const display = typeof raw === 'string' ? raw : JSON.stringify(raw || {}, null, 2);

      return el(TextareaControl, {
        label: field.label + (field.required ? ' *' : ''),
        help: field.help || __('Enter valid JSON.', 'noble-schema'),
        value: display,
        rows: 6,
        onChange: (next) => setManualValue(setAttributes, attributes, field.key, next),
      });
    }

    return el(TextControl, {
      label: field.label + (field.required ? ' *' : ''),
      help: field.help,
      value: manualValue(attributes, field.key, ''),
      onChange: (next) => setManualValue(setAttributes, attributes, field.key, next),
    });
  }

  function groupMappings(mappings) {
    const groups = [];
    const byConfig = new Map();

    mappings.forEach((field) => {
      const id = String(field.config_id || '');
      if (!byConfig.has(id)) {
        const group = {
          id,
          label: field.config_label || __('Schema', 'noble-schema'),
          fields: [],
        };
        byConfig.set(id, group);
        groups.push(group);
      }
      byConfig.get(id).fields.push(field);
    });

    return groups;
  }

  function settingsWithManualAttribute(settings) {
    return {
      ...(settings || {}),
      attributes: {
        ...((settings && settings.attributes) || {}),
        [valuesAttribute]: {
          type: 'object',
          default: {},
        },
      },
    };
  }

  function addManualAttribute(settings) {
    return {
      ...settingsWithManualAttribute(settings),
      deprecated: Array.isArray(settings.deprecated)
        ? settings.deprecated.map((version) => settingsWithManualAttribute(version))
        : settings.deprecated,
    };
  }

  function withNobleSchemaInspector(BlockEdit) {
    return function NobleSchemaBlockEdit(props) {
      const mappings = mappingsForBlock(props.name);
      const groups = groupMappings(mappings);
      const registered = !!getBlockType(props.name)?.attributes?.[valuesAttribute];

      return el(
        Fragment,
        null,
        el(BlockEdit, props),
        props.isSelected && groups.length
          ? el(
              InspectorControls,
              null,
              el(
                PanelBody,
                { title: __('Noble Schema', 'noble-schema'), initialOpen: true },
                !registered
                  ? el(
                      'p',
                      null,
                      __(
                        'This block does not support Noble Schema values. Reload the editor, then verify the block is registered by WordPress.',
                        'noble-schema'
                      )
                    )
                  : groups.map((group, groupIndex) =>
                  el(
                    'div',
                    {
                      key: group.id,
                      style: groupIndex > 0
                        ? { borderTop: '1px solid #dcdcde', marginTop: '1em', paddingTop: '1em' }
                        : {},
                    },
                    el(
                      'strong',
                      { style: { display: 'block', marginBottom: '0.75em' } },
                      group.label
                    ),
                    group.fields.map((field) =>
                      el(ManualField, {
                        key: field.key,
                        attributes: props.attributes,
                        setAttributes: props.setAttributes,
                        field,
                      })
                    )
                  )
                )
              )
            )
          : null
      );
    };
  }

  addFilter(
    'blocks.registerBlockType',
    'noble-schema/manual-block-attributes',
    addManualAttribute
  );
  addFilter(
    'editor.BlockEdit',
    'noble-schema/manual-block-inspector',
    withNobleSchemaInspector
  );
})(window.wp);
