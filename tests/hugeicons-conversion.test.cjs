const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const {
	parseIcon,
	createConverter,
} = require("../scripts/hugeicons-build/convert.cjs");

test("package icon parser reads data without executing JavaScript", () => {
	assert.deepEqual(
		parseIcon(
			'const Icon = [["path", {d:"M1 1h2",stroke:"currentColor"}]]; module.exports=Icon;',
		),
		[["path", { d: "M1 1h2", stroke: "currentColor" }]],
	);
	assert.throws(() => parseIcon("const Icon = process.exit();"));
	assert.throws(() =>
		parseIcon('const Icon = [["path", {get d(){return "M1 1"}}]];'),
	);
	assert.throws(() => parseIcon('const Icon = [["path", {__proto__: "x"}]];'));
});

test("stroke widths, caps, joins, fills and transforms are retained geometrically", async () => {
	const convert = await createConverter();
	const data = [
		[
			"path",
			{
				d: "M2 12h20",
				stroke: "currentColor",
				strokeWidth: "1.5",
				strokeLinecap: "round",
				strokeLinejoin: "round",
			},
		],
	];
	const result = convert(data);
	assert.equal(result, convert(data));
	assert.doesNotMatch(result, /stroke/);
	assert.notEqual(
		result,
		convert([["path", { ...data[0][1], strokeWidth: "3" }]]),
	);
	assert.notEqual(
		result,
		convert([["path", { ...data[0][1], strokeLinecap: "square" }]]),
	);
	assert.notEqual(
		result,
		convert([["path", { ...data[0][1], transform: "rotate(180 12 12)" }]]),
	);
	const filled = convert([
		["circle", { cx: "12", cy: "12", r: "2", fill: "currentColor" }],
	]);
	assert.match(filled, /<path fill="currentColor"/);
	assert.throws(() => convert([["path", { ...data[0][1], opacity: "0.4" }]]));
	assert.throws(() =>
		convert([["path", { ...data[0][1], strokeDasharray: "2 2" }]]),
	);
	assert.throws(() =>
		convert([
			["path", { ...data[0][1], stroke: "url(https://example.com/x)" }],
		]),
	);
});

test("manifest preserves exact free collection provenance and exclusions", () => {
	const root = path.join(__dirname, "../assets/icons/hugeicons");
	const manifest = JSON.parse(
		fs.readFileSync(path.join(root, "manifest.json")),
	);
	const report = JSON.parse(
		fs.readFileSync(path.join(root, "exclusions.json")),
	);
	assert.equal(manifest.version, "4.3.5");
	assert.equal(
		manifest.source.revision,
		"bf880d758a69ab69edb278f8b529579fac54e5df",
	);
	assert.equal(manifest.icons.length, 6064);
	assert.equal(manifest.variants[0].defaultEnabled, false);
	assert.equal(manifest.variants[0].coreCompatible, true);
	assert.equal(report.excludedIconCount, 3);
	assert.equal(report.sourcePackage, "@hugeicons/core-free-icons");
	assert.match(
		fs.readFileSync(path.join(root, "LICENSE"), "utf8"),
		/Copyright \(c\) 2025 Hugeicons/,
	);
	for (const icon of manifest.icons) {
		assert.match(icon.id, /^hugeicons\/stroke-rounded\//);
		assert.doesNotMatch(
			fs.readFileSync(path.join(root, icon.path), "utf8"),
			/stroke|opacity|<circle|<rect|<ellipse/,
		);
	}
});
