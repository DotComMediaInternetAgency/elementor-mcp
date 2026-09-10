# GSAP Integration

EMCP Tools Pro 3.16.0 includes GSAP 3.15.0 as an opt-in module. It registers
the official local UMD distribution with WordPress and does not use a CDN.

## Enabling the module

Open **EMCP Tools > Modules**, enable **GSAP Integration**, save the modules,
then open **Show Settings**. Core is loaded in every selected context. All 24
plugins are selected initially and can be disabled individually.

The available contexts are the site frontend, WordPress admin, block editor,
and Elementor editor. Only the site frontend is selected by default.

## WordPress handles

GSAP core is registered as `emcp-gsap`. Plugin handles use the catalog id,
for example `emcp-gsap-scroll-trigger`, `emcp-gsap-split-text`, and
`emcp-gsap-motion-path`.

Core already includes CSSPlugin, AttrPlugin, EndArrayPlugin, ModifiersPlugin,
RoundPropsPlugin, and SnapPlugin. They do not have separate files or switches.

Theme or plugin code can declare these handles as normal script dependencies:

```php
wp_enqueue_script(
	'my-animation',
	get_stylesheet_directory_uri() . '/animations.js',
	array( 'emcp-gsap', 'emcp-gsap-scroll-trigger' ),
	'1.0.0',
	true
);
```

Every handle is registered when the active module boots. Selected handles are
also enqueued in the configured contexts. The action
`emcp_tools_gsap_registered` fires after registration, and
`EMCP_Tools_GSAP_Assets::enqueue_plugin( $id )` is available for integrations
that need to request a catalog plugin directly.

`PixiPlugin` requires PixiJS and `EaselPlugin` requires CreateJS/EaselJS; those
unrelated runtimes are not bundled. Plugin-to-plugin dependencies such as
ScrollSmoother to ScrollTrigger are resolved automatically.

## MCP tools

- `gsap-read`: list operations, read status, and list the plugin catalog with
  handles, dependencies, external prerequisites, and runtime state.
- `gsap-write`: replace contexts/plugin selections or toggle one plugin. Turning
  off a dependency through `set-plugin` also turns off plugins that require it.

The read tool requires `edit_posts`; writes require `manage_options`. The tools
are registered only while the Pro module is active and licensed.

## Agent skill

The Pro skills catalog includes the singular `emcp-gsap` skill. It consolidates
the official GreenSock guidance for core tweens, timelines, ScrollTrigger,
plugins, utilities, framework lifecycle, and performance, then adds the EMCP
dispatcher workflow, stable WordPress handles, Elementor lifecycle patterns,
reduced-motion requirements, and real-browser verification.

The skill is available at runtime through `list-skills` / `get-skill` and is
packaged in both Skills-tab downloads: the standard folder bundle contains
`emcp-gsap/SKILL.md`, while the Claude Desktop bundle contains
`emcp-gsap.zip` with that skill as its root package.
