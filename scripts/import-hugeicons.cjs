"use strict";

// Usage: node scripts/import-hugeicons.cjs /path/to/hugeicons-core-free-icons-4.3.5.tgz
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const crypto = require("node:crypto");
const { execFileSync } = require("node:child_process");
const { parseIcon, createConverter } = require("./hugeicons-build/convert.cjs");
const version = "4.3.5";
const revision = "bf880d758a69ab69edb278f8b529579fac54e5df";
const integrity =
	"Sv+NjHRPnQk+yZsGCMcGznCHdTfJR9PMMOEKcJYAQU4gy90dmlc9PwdtvYOImBZIjiheMsTLL5eMn2DmHxUiUg==";
const expectedExclusions = [
	"ArrowBigRightDashIcon.js",
	"Hamburger01Icon.js",
	"RightToLeftListBulletIcon.js",
];
const json = (value) => JSON.stringify(value, null, 4) + "\n";

async function main() {
	if (!process.argv[2])
		throw new Error("Provide the pinned official free-package tarball.");
	const archive = fs.realpathSync(process.argv[2]);
	if (
		crypto
			.createHash("sha512")
			.update(fs.readFileSync(archive))
			.digest("base64") !== integrity
	)
		throw new Error(
			"Package integrity does not match the pinned official free release.",
		);
	const source = fs.realpathSync(fs.mkdtempSync(
		path.join(os.tmpdir(), "ail-hugeicons-import-"),
	));
	const parent = fs.realpathSync(path.join(__dirname, "../assets/icons"));
	const destination = path.join(parent, "hugeicons");
	if (
		fs.existsSync(destination) &&
		(fs.lstatSync(destination).isSymbolicLink() ||
			!fs.existsSync(path.join(destination, "manifest.json")))
	)
		throw new Error("Unsafe destination.");
	const build = fs.mkdtempSync(path.join(parent, "hugeicons.tmp-"));
	try {
		// The exact archive digest is checked before extraction; no upstream code runs.
		execFileSync("tar", ["-xzf", archive, "-C", source]);
		function read(relative) {
			const full = path.join(source, "package", relative);
			if (
				fs.lstatSync(full).isSymbolicLink() ||
				!fs.realpathSync(full).startsWith(source + path.sep)
			)
				throw new Error("Source file escapes extraction.");
			return fs.readFileSync(full, "utf8");
		}
		const pkg = JSON.parse(read("package.json"));
		if (
			pkg.name !== "@hugeicons/core-free-icons" ||
			pkg.version !== version ||
			pkg.license !== "MIT"
		)
			throw new Error("Unexpected package identity.");
		const license = read("LICENSE.md");
		if (
			!license.includes("Copyright (c) 2025 Hugeicons") ||
			!license.includes("MIT License")
		)
			throw new Error("Unexpected license notice.");
		fs.mkdirSync(path.join(build, "stroke-rounded"));
		fs.writeFileSync(path.join(build, "LICENSE"), license);
		const files = fs
			.readdirSync(path.join(source, "package/dist/cjs"))
			.filter((name) => name.endsWith("Icon.js"))
			.sort();
		if (files.length !== 6067) throw new Error("Unexpected source icon count.");
		const convert = await createConverter();
		const icons = [],
			exclusions = [],
			ids = new Set();
		for (const file of files) {
			const base = file.slice(0, -7);
			const slug = base
				.replace(/([A-Z]+)([A-Z][a-z])/g, "$1-$2")
				.replace(/([a-z0-9])([A-Z])/g, "$1-$2")
				.toLowerCase();
			if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug) || ids.has(slug))
				throw new Error("Invalid or colliding source identifier: " + file);
			ids.add(slug);
			let svg;
			try {
				svg = convert(parseIcon(read("dist/cjs/" + file)));
			} catch (error) {
				if (
					!expectedExclusions.includes(file) ||
					error.message !== "Per-element opacity is not preserved by Core."
				)
					throw error;
				exclusions.push({
					slug,
					variant: "stroke-rounded",
					sourceFile: file,
					reason: error.message,
				});
				continue;
			}
			const relative = "stroke-rounded/" + slug + ".svg",
				content = svg + "\n";
			fs.writeFileSync(path.join(build, relative), content);
			icons.push({
				id: "hugeicons/stroke-rounded/" + slug,
				coreIconName: "hugeicons/" + slug + "-stroke-rounded",
				label: slug
					.split("-")
					.map((word) => word[0].toUpperCase() + word.slice(1))
					.join(" "),
				variant: "stroke-rounded",
				categories: ["general"],
				keywords: [...new Set(slug.split("-"))],
				path: relative,
				sha256: crypto.createHash("sha256").update(content).digest("hex"),
			});
		}
		if (icons.length !== 6064 || exclusions.length !== 3)
			throw new Error("Compatibility scope changed unexpectedly.");
		const manifest = {
			schemaVersion: 2,
			slug: "hugeicons",
			name: "Hugeicons",
			description:
				"The MIT-licensed free Stroke Rounded collection, excluding three opacity-dependent icons.",
			version,
			license: {
				name: "MIT",
				url: `https://github.com/hugeicons/hugeicons/blob/${revision}/LICENSE.md`,
			},
			source: {
				name: "@hugeicons/core-free-icons",
				url: "https://github.com/hugeicons/hugeicons",
				revision,
			},
			variants: [
				{
					slug: "stroke-rounded",
					label: "Stroke Rounded",
					coreCompatible: true,
					defaultEnabled: false,
					iconCount: icons.length,
				},
			],
			categories: [
				{ slug: "general", label: "General", iconCount: icons.length },
			],
			icons,
		};
		fs.writeFileSync(path.join(build, "manifest.json"), json(manifest));
		fs.writeFileSync(
			path.join(build, "exclusions.json"),
			json({
				schemaVersion: 1,
				slug: "hugeicons",
				version,
				sourceRevision: revision,
				sourcePackage: "@hugeicons/core-free-icons",
				sourceIntegrity: "sha512-" + integrity,
				includedIconCount: icons.length,
				excludedIconCount: exclusions.length,
				exclusions,
			}),
		);
		execFileSync(
			"php",
			[
				"-r",
				'require $argv[1]; $m=json_decode(file_get_contents($argv[2]."/manifest.json"),true); $e=IconLibrary\\Build\\CollectionBuild::validate_manifest($m,$argv[2]); if($e){fwrite(STDERR,implode("\\n",$e));exit(1);}',
				path.join(__dirname, "lib/CollectionBuild.php"),
				build,
			],
			{ stdio: "inherit" },
		);
		const backup = build + ".previous";
		if (fs.existsSync(destination)) fs.renameSync(destination, backup);
		try {
			fs.renameSync(build, destination);
		} catch (error) {
			if (fs.existsSync(backup)) fs.renameSync(backup, destination);
			throw error;
		}
		if (fs.existsSync(backup)) fs.rmSync(backup, { recursive: true });
		console.log(
			`Imported ${icons.length} Hugeicons free ${version} icons; ${exclusions.length} documented exclusions.`,
		);
	} finally {
		fs.rmSync(source, { recursive: true });
		if (fs.existsSync(build)) fs.rmSync(build, { recursive: true });
	}
}

main().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
