"use strict";

const fs = require("node:fs");
const acorn = require("acorn");
const svgpath = require("svgpath");
const PathKitInit = require("pathkit-wasm");

function literal(node) {
	if (
		node.type === "Literal" &&
		["string", "number"].includes(typeof node.value)
	)
		return node.value;
	if (node.type === "ArrayExpression") return node.elements.map(literal);
	if (node.type === "ObjectExpression")
		return Object.fromEntries(
			node.properties.map((prop) => {
				if (
					prop.type !== "Property" ||
					prop.computed ||
					prop.method ||
					prop.kind !== "init"
				)
					throw new Error("Non-data property.");
				const key =
					prop.key.type === "Identifier" ? prop.key.name : literal(prop.key);
				if (["__proto__", "constructor", "prototype"].includes(key))
					throw new Error("Unsafe property.");
				return [key, literal(prop.value)];
			}),
		);
	throw new Error("Non-data JavaScript node.");
}

function parseIcon(source) {
	const ast = acorn.parse(source, { ecmaVersion: 2022 });
	const declarations = ast.body.filter(
		(node) => node.type === "VariableDeclaration",
	);
	if (declarations.length !== 1 || declarations[0].declarations.length !== 1)
		throw new Error("Unexpected icon declaration.");
	return literal(declarations[0].declarations[0].init);
}

async function createConverter() {
	const kit = await PathKitInit({
		wasmBinary: fs.readFileSync(
			require.resolve("pathkit-wasm/bin/pathkit.wasm"),
		),
	});
	const geometry = {
		path: ["d"],
		circle: ["cx", "cy", "r"],
		ellipse: ["cx", "cy", "rx", "ry"],
		rect: ["x", "y", "width", "height", "rx", "ry"],
	};
	const style = [
		"stroke",
		"strokeWidth",
		"strokeLinecap",
		"strokeLinejoin",
		"fill",
		"fillRule",
		"clipRule",
		"transform",
		"key",
	];
	return function convert(nodes) {
		const paths = [];
		for (const [tag, a] of nodes) {
			if (Object.hasOwn(a, "opacity"))
				throw new Error("Per-element opacity is not preserved by Core.");
			if (
				!geometry[tag] ||
				Object.keys(a).some(
					(key) => !geometry[tag].includes(key) && !style.includes(key),
				)
			)
				throw new Error("Unsupported geometry or presentation.");
			const number = (name, fallback = 0) => {
				const value = Object.hasOwn(a, name) ? Number(a[name]) : fallback;
				if (!Number.isFinite(value))
					throw new Error("Invalid geometry number.");
				return value;
			};
			let d = a.d;
			if (tag === "circle" || tag === "ellipse") {
				const x = number("cx"),
					y = number("cy"),
					rx = number(tag === "circle" ? "r" : "rx"),
					ry = tag === "circle" ? rx : number("ry");
				if (rx <= 0 || ry <= 0) throw new Error("Invalid ellipse.");
				d = `M${x - rx} ${y}a${rx} ${ry} 0 1 0 ${rx * 2} 0a${rx} ${ry} 0 1 0 ${
					-rx * 2
				} 0Z`;
			}
			if (tag === "rect") {
				const x = number("x"),
					y = number("y"),
					w = number("width"),
					h = number("height");
				let rx = number("rx", number("ry")),
					ry = number("ry", rx);
				if (w <= 0 || h <= 0 || rx < 0 || ry < 0)
					throw new Error("Invalid rectangle.");
				rx = Math.min(rx, w / 2);
				ry = Math.min(ry, h / 2);
				d =
					rx && ry
						? `M${x + rx} ${y}H${x + w - rx}A${rx} ${ry} 0 0 1 ${x + w} ${
								y + ry
						  }V${y + h - ry}A${rx} ${ry} 0 0 1 ${x + w - rx} ${y + h}H${
								x + rx
						  }A${rx} ${ry} 0 0 1 ${x} ${y + h - ry}V${
								y + ry
						  }A${rx} ${ry} 0 0 1 ${x + rx} ${y}Z`
						: `M${x} ${y}h${w}v${h}h${-w}Z`;
			}
			const parsed = svgpath(d);
			if (parsed.err) throw new Error(parsed.err);
			const p = kit.FromSVGString(parsed.toString());
			if (!p) throw new Error("Invalid Skia path.");
			function emit(data, fillRule = "nonzero") {
				if (!["nonzero", "evenodd"].includes(fillRule))
					throw new Error("Unsupported fill rule.");
				const transformed = svgpath(data);
				if (a.transform) {
					if (!/^(matrix|rotate)\([-+0-9.eE ,]+\)$/.test(a.transform))
						throw new Error("Unsupported transform.");
					transformed.transform(a.transform);
				}
				const result = transformed.toString();
				if (transformed.err || !result || /[^a-zA-Z0-9., +\-]/.test(result))
					throw new Error("Invalid generated path.");
				paths.push(
					`<path fill="currentColor" fill-rule="${fillRule}" d="${result}"/>`,
				);
			}
			try {
				if (a.fill !== undefined) {
					if (a.fill !== "currentColor") throw new Error("Unsupported fill.");
					emit(p.toSVGString(), a.fillRule);
				}
				if (a.stroke !== undefined) {
					if (a.stroke !== "currentColor")
						throw new Error("Unsupported stroke.");
					const caps = {
						butt: kit.StrokeCap.BUTT,
						round: kit.StrokeCap.ROUND,
						square: kit.StrokeCap.SQUARE,
					};
					const joins = {
						miter: kit.StrokeJoin.MITER,
						round: kit.StrokeJoin.ROUND,
						bevel: kit.StrokeJoin.BEVEL,
					};
					const cap = a.strokeLinecap || "butt",
						join = a.strokeLinejoin || "miter",
						width = number("strokeWidth", 1);
					if (
						!Object.hasOwn(caps, cap) ||
						!Object.hasOwn(joins, join) ||
						width <= 0
					)
						throw new Error("Unsupported stroke style.");
					if (
						!p.stroke({
							width,
							cap: caps[cap],
							join: joins[join],
							miter_limit: 4,
						})
					)
						throw new Error("Skia stroke expansion failed.");
					emit(p.toSVGString());
				}
				if (a.fill === undefined && a.stroke === undefined)
					throw new Error("Invisible source geometry.");
			} finally {
				p.delete();
			}
		}
		if (!paths.length) throw new Error("Empty icon.");
		return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">${paths.join(
			"",
		)}</svg>`;
	};
}

module.exports = { parseIcon, createConverter };
