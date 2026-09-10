# ACF MCP Coverage

Verified on 2026-09-10 against `https://elementor-mcp.test/` with ACF Pro 6.8.9 and the real EMCP MCP transport. This is a runtime-tested coverage statement, not a claim of complete ACF product parity.

## Built-in field types

`acf-read` exposes `list-field-types`, which reads the active ACF registry instead of relying on a hard-coded catalog. The test site registered 36 public field types. All 36 could be created in one field group and read back through MCP.

| Edition | Field types verified |
| --- | --- |
| ACF Free | text, textarea, number, range, email, url, password, image, file, wysiwyg, oembed, select, checkbox, radio, button_group, true_false, link, post_object, page_link, relationship, taxonomy, user, google_map, date_picker, date_time_picker, time_picker, color_picker, icon_picker, message, accordion, tab, group |
| ACF Pro | repeater, flexible_content, gallery, clone |

Thirty-three types store values. `message`, `accordion`, and `tab` are presentation controls and intentionally reject value writes. Nested group, repeater, flexible-content, and clone values use structured JSON. The live matrix verified schema persistence, preview, batch write, and read-back for every storage type.

Built-in validation currently covers required values, scalar and collection shapes, text length, email, HTTP(S) URLs, numeric min/max/step, configured choices, booleans, links, attachments and media constraints, related object existence/type, taxonomy and user restrictions, map coordinates, strict stored date/time formats, colors, icons, nested subfields, repeater/gallery/relationship counts, and flexible-content layout counts. ACF Pro bidirectional relationships were also verified through MCP for both add and remove behavior.

## Feature coverage

| ACF feature | MCP coverage | Boundary |
| --- | --- | --- |
| Field discovery | Covered | Discovers the active runtime, including registered third-party types and their setting keys. |
| Field values | Covered for posts and existing options pages | Supports read, validated write, dry-run planning, hash-bound batch apply, provenance, and read-back. |
| Field groups | Partial | List/get/create/update, append fields, and update field settings. No delete, duplicate, move, reorder, rename, or field-type mutation. |
| Built-in field settings | Broad | The settings registered by ACF 6.8.9 are preserved for built-in fields. Field-group presentation settings such as style, label placement, instruction placement, hidden-screen controls, description, and REST exposure are not authorable yet. |
| Location rules | Partial | Rule groups are persisted, but MCP validates only their shape and operator; it does not enumerate or validate every location type/value supplied by extensions. |
| Options pages (Pro) | Partial | List and field read/write work for pages already registered on the site. MCP cannot create, update, delete, or reorder options pages. |
| Bidirectional relationships | Covered | Relationship target settings, reverse writes, and reverse cleanup passed live MCP tests. |
| ACF-managed post types | Partial | List/get plus basic create/update for labels, visibility, REST, hierarchy, supports, archive, and taxonomies. The complete ACF labels, rewrite, capability, menu, query, and admin settings are not exposed. |
| ACF-managed taxonomies | Partial | List/get plus basic create/update for labels, object types, visibility, REST, and hierarchy. The complete ACF labels, rewrite, capability, query, and admin settings are not exposed. |
| ACF Blocks (Pro) | Not covered | The Gutenberg tools do not create or manage ACF block definitions. |
| Local JSON and PHP-registered groups | Read only | Local groups can be discovered but are deliberately refused by the editor to avoid creating a database copy that shadows code. There is no JSON sync/import/export API. |
| Additional ACF object targets | Not covered | Value operations currently accept numeric post IDs or options-page names, not term, user, comment, widget, or menu-item targets. |
| Third-party field types | Partial | Discovery and basic schema creation work when the type is registered. Type-specific settings outside the allowlist and custom `acf/validate_value` behavior are not guaranteed. |

## Document imports

PDFs, spreadsheets, CSV files, DOCX files, and OCR output are parsed by the agent. WordPress receives only bounded structured JSON through `validate-fields` and `batch-update-fields`; it does not parse source documents on the server. A failed item rejects the complete batch before any write.

## Next implementation priorities

1. Add options-page list/get/create/update operations, with delete remaining separately permissioned and confirmation-gated.
2. Expand field-group presentation settings and add explicit field ordering/move operations.
3. Expand ACF-managed post-type and taxonomy schemas to match the full ACF UI.
4. Add target descriptors for terms, users, comments, widgets, and menu items.
5. Add ACF Blocks discovery and authoring.
6. Delegate validation of registered custom field types to ACF without leaking validation state between batch items.

References: [ACF field types](https://www.advancedcustomfields.com/resources/), [ACF Pro features](https://www.advancedcustomfields.com/pro/), [Repeater](https://www.advancedcustomfields.com/resources/repeater/), [Flexible Content](https://www.advancedcustomfields.com/resources/flexible-content/), [Clone](https://www.advancedcustomfields.com/resources/clone/), [Gallery](https://www.advancedcustomfields.com/resources/how-to-use-the-gallery-field/), and [Bidirectional relationships](https://www.advancedcustomfields.com/resources/bidirectional-relationships/).
