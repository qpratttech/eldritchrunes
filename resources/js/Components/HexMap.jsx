import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';

let gridScale = 1;
const hexSize = () => 33 * gridScale;
const cellSize = () => 54 * gridScale;

function center(col, row, square = false) {
    const size = hexSize();
    const cell = cellSize();
    if (square) {
        return { x: col * cell + cell / 2, y: row * cell + cell / 2 };
    }
    const x = size * Math.sqrt(3) * (col + 0.5 * (row & 1));
    const y = size * 1.5 * row;
    return { x: x + size, y: y + size };
}

function polygon(col, row, square = false) {
    const size = hexSize();
    const cell = cellSize();
    const { x, y } = center(col, row, square);
    if (square) {
        const half = cell / 2;
        return [[x - half, y - half], [x + half, y - half], [x + half, y + half], [x - half, y + half]];
    }
    return Array.from({ length: 6 }, (_, i) => {
        const angle = (Math.PI / 180) * (60 * i - 30);
        return [x + size * Math.cos(angle), y + size * Math.sin(angle)];
    });
}

function cube(col, row) {
    const x = col - (row - (row & 1)) / 2;
    const z = row;
    return { x, y: -x - z, z };
}

function distance(a, b, square = false) {
    if (square) {
        return Math.max(Math.abs(a.x - b.x), Math.abs(a.y - b.y));
    }
    const left = cube(a.x, a.y);
    const right = cube(b.x, b.y);
    return Math.max(Math.abs(left.x - right.x), Math.abs(left.y - right.y), Math.abs(left.z - right.z));
}

function lineCells(from, to, square = false) {
    if (!from || !to) return [];
    if (from.x === to.x && from.y === to.y) return [from];
    if (!square) {
        const steps = Math.max(distance(from, to), 1);
        const cells = [{ x: from.x, y: from.y }];
        let last = `${from.x},${from.y}`;
        for (let i = 1; i <= steps; i += 1) {
            const col = Math.round(from.x + ((to.x - from.x) * i) / steps);
            const row = Math.round(from.y + ((to.y - from.y) * i) / steps);
            const key = `${col},${row}`;
            if (key === last) continue;
            cells.push({ x: col, y: row });
            last = key;
        }
        return cells;
    }
    const cells = [{ x: from.x, y: from.y }];
    let x = from.x;
    let y = from.y;
    const dx = Math.abs(to.x - from.x);
    const dy = Math.abs(to.y - from.y);
    const sx = from.x < to.x ? 1 : -1;
    const sy = from.y < to.y ? 1 : -1;
    let err = dx - dy;
    while (x !== to.x || y !== to.y) {
        const e2 = 2 * err;
        if (e2 > -dy) {
            err -= dy;
            x += sx;
        }
        if (e2 < dx) {
            err += dx;
            y += sy;
        }
        cells.push({ x, y });
    }
    return cells;
}

function crosses(from, to, blocked, includeDestination, square = false) {
    if (square) {
        return squareCrosses(from, to, blocked, includeDestination);
    }
    const steps = Math.max(distance(from, to), 1);
    const last = includeDestination ? steps : steps - 1;
    for (let i = 1; i <= last; i += 1) {
        const col = Math.round(from.x + ((to.x - from.x) * i) / steps);
        const row = Math.round(from.y + ((to.y - from.y) * i) / steps);
        if (blocked.has(`${col},${row}`)) return true;
    }
    return false;
}

function squareCrosses(from, to, blocked, includeDestination) {
    let x = from.x;
    let y = from.y;
    const dx = Math.abs(to.x - from.x);
    const dy = Math.abs(to.y - from.y);
    const sx = from.x < to.x ? 1 : -1;
    const sy = from.y < to.y ? 1 : -1;
    let err = dx - dy;
    const cells = [];
    while (x !== to.x || y !== to.y) {
        const e2 = 2 * err;
        if (e2 > -dy) {
            err -= dy;
            x += sx;
        }
        if (e2 < dx) {
            err += dx;
            y += sy;
        }
        cells.push([x, y]);
    }
    const last = includeDestination ? cells.length : Math.max(0, cells.length - 1);
    for (let i = 0; i < last; i += 1) {
        if (blocked.has(`${cells[i][0]},${cells[i][1]}`)) return true;
    }
    return false;
}

function blockedLos(from, to, los, square = false) {
    return crosses(from, to, los, false, square);
}

function pathBlocked(from, to, blocks, square = false) {
    return crosses(from, to, blocks, true, square);
}

export default function HexMap({ scene, tokens = [], isGm, tool, blockIcon = 'rock', userId, movement = 'open', activeCombatantId, gmVision = false, scale = 1, onPick }) {
    gridScale = Math.min(2, Math.max(0.5, Number(scale) || 1)) * 2;
    const art = usePage().props.art || {};
    const mapBackgrounds = usePage().props.mapBackgrounds || ['field', 'dungeon'];
    const ref = useRef(null);
    const viewportRef = useRef(null);
    const viewRef = useRef({ zoom: 1, x: 0, y: 0 });
    const fittedFor = useRef(null);
    const baseFit = useRef(1);
    const pan = useRef(null);
    const drag = useRef(null);
    const paintStroke = useRef(null);
    const [stroke, setStroke] = useState([]);
    const lightIcon = useRef(null);
    const turnIcon = useRef(null);
    const shade = useRef(null);
    const blockIcons = useRef({});
    const backgrounds = useRef({});
    const portraits = useRef(new Map());
    const [ghost, setGhost] = useState(null);
    const [iconReady, setIconReady] = useState(false);
    const [turnReady, setTurnReady] = useState(false);
    const [blocksReady, setBlocksReady] = useState(false);
    const [backgroundTick, setBackgroundTick] = useState(0);
    const [portraitTick, setPortraitTick] = useState(0);
    const [hover, setHover] = useState(null);
    const [measure, setMeasure] = useState(null);
    const measureDrag = useRef(false);
    const [view, setView] = useState(viewRef.current);
    const settings = scene?.settings_json || { cols: 80, rows: 48, lighting: 'night-gloom' };
    const cols = settings.cols || 24;
    const rows = settings.rows || 16;
    const wall = (scene?.walls || []).find((row) => row.wall_type === 'hex');
    const terrain = wall?.geometry_json?.terrain || {};
    const terrainKeys = Object.keys(terrain);
    const move = new Set([...(wall?.geometry_json?.move || []), ...terrainKeys]);
    const los = new Set([...(wall?.geometry_json?.los || []), ...terrainKeys]);
    const lights = scene?.lights || [];
    const square = settings.grid === 'square';
    const showGrid = settings.show_grid !== false;

    function lightCell(source) {
        if (!source.token_id) return source;
        if (ghost?.id === source.token_id) return ghost;
        return tokens.find((token) => token.id === source.token_id) || source;
    }

    function lightReach(source) {
        const feet = scene?.feet_per_cell || 5;
        const step = square ? cellSize() : hexSize() * Math.sqrt(3);
        const brightPx = Math.max(step * 0.55, ((source.bright_radius_ft || 20) / feet) * step);
        return Math.max(brightPx + step * 0.8, (((source.bright_radius_ft || 20) + (source.dim_radius_ft || 20)) / feet) * step);
    }

    function npcHidden(token) {
        if (gmVision || settings.lighting === 'day' || settings.lighting === 'twilight') return false;
        const combatant = token.combatant;
        if (!combatant?.creature_instance_id || combatant.character_id) return false;
        const at = ghost?.id === token.id ? ghost : token;
        const point = center(at.x, at.y, square);
        const litByFlame = lights.some((source) => {
            if (source.enabled === false) return false;
            const origin = center(lightCell(source).x, lightCell(source).y, square);
            return Math.hypot(point.x - origin.x, point.y - origin.y) <= lightReach(source);
        });
        if (litByFlame) return false;
        const feet = scene?.feet_per_cell || 5;
        const step = square ? cellSize() : hexSize() * Math.sqrt(3);
        const sight = (18 / feet) * step;

        return !tokens.some((viewer) => {
            if (!viewer.nightvision) return false;
            const at = ghost?.id === viewer.id ? ghost : viewer;
            const origin = center(at.x, at.y, square);
            return Math.hypot(point.x - origin.x, point.y - origin.y) <= sight;
        });
    }

    function npcInFog(token) {
        if (gmVision) return false;
        const combatant = token.combatant;
        if (!combatant?.creature_instance_id || combatant.character_id) return false;
        const at = ghost?.id === token.id ? ghost : token;
        const key = `${at.x},${at.y}`;
        if ((settings.fogged || []).includes(key)) return true;
        if (settings.fog !== true) return false;
        return !(settings.explored || []).includes(key);
    }

    function paintNightvision(shadeCtx) {
        const feet = scene?.feet_per_cell || 5;
        const step = square ? cellSize() : hexSize() * Math.sqrt(3);
        const twilight = settings.lighting === 'twilight';
        tokens.filter((token) => token.nightvision).forEach((token) => {
            const at = ghost?.id === token.id ? ghost : token;
            const { x, y } = center(at.x, at.y, square);
            const reach = ((twilight ? 40 : 18) / feet) * step;
            const gradient = shadeCtx.createRadialGradient(x, y, 0, x, y, reach);
            if (twilight) {
                gradient.addColorStop(0, 'rgba(0,0,0,1)');
                gradient.addColorStop(0.62, 'rgba(0,0,0,0.92)');
                gradient.addColorStop(0.84, 'rgba(0,0,0,0.4)');
                gradient.addColorStop(1, 'rgba(0,0,0,0)');
            } else {
                gradient.addColorStop(0, 'rgba(0,0,0,0.46)');
                gradient.addColorStop(0.55, 'rgba(0,0,0,0.24)');
                gradient.addColorStop(1, 'rgba(0,0,0,0)');
            }
            shadeCtx.fillStyle = gradient;
            shadeCtx.beginPath();
            shadeCtx.arc(x, y, reach, 0, Math.PI * 2);
            shadeCtx.fill();
        });
    }

    function mapSize() {
        if (square) {
            return { width: cols * cellSize(), height: rows * cellSize() };
        }
        return {
            width: center(cols - 1, 0).x + hexSize() * 2,
            height: center(0, rows - 1).y + hexSize() * 2,
        };
    }

    function gridOffset() {
        const saved = gridScale;
        gridScale = 1;
        const base = mapSize();
        gridScale = saved;
        const scaled = mapSize();
        return {
            x: (base.width - scaled.width) / 2,
            y: (base.height - scaled.height) / 2,
            width: scaled.width,
            height: scaled.height,
        };
    }

    function commitView(next) {
        const el = viewportRef.current;
        const bounds = gridOffset();
        if (el) {
            const scaledWidth = bounds.width * next.zoom;
            const scaledHeight = bounds.height * next.zoom;
            const spareX = el.clientWidth - scaledWidth;
            const spareY = el.clientHeight - scaledHeight;
            const panX = next.x + bounds.x * next.zoom;
            const panY = next.y + bounds.y * next.zoom;
            const clampedX = Math.min(Math.max(panX, Math.min(0, spareX)), Math.max(0, spareX));
            const clampedY = Math.min(Math.max(panY, Math.min(0, spareY)), Math.max(0, spareY));
            next = {
                zoom: next.zoom,
                x: clampedX - bounds.x * next.zoom,
                y: clampedY - bounds.y * next.zoom,
            };
        }
        viewRef.current = next;
        setView(next);
    }

    useEffect(() => {
        const image = new Image();
        image.src = art.firelight || '/images/firelight.webp';
        image.onload = () => {
            lightIcon.current = image;
            setIconReady(true);
        };
        const turn = new Image();
        turn.onload = () => {
            turnIcon.current = turn;
            setTurnReady(true);
        };
        turn.src = art['turn-icon'] || '/images/turn-icon.webp';
        let remaining = 2;
        ['rock', 'tree'].forEach((kind) => {
            const icon = new Image();
            icon.onload = () => {
                blockIcons.current[kind] = icon;
                remaining -= 1;
                if (remaining === 0) setBlocksReady(true);
            };
            icon.src = art[kind] || `/images/${kind}.webp`;
        });
        mapBackgrounds.forEach((name) => {
            const image = new Image();
            image.onload = () => {
                backgrounds.current[name] = image;
                setBackgroundTick((tick) => tick + 1);
            };
            image.src = art[name] || `/images/${name}.jpg`;
        });
    }, [art.firelight, art['turn-icon'], art.rock, art.tree, mapBackgrounds.join('|'), mapBackgrounds.map((name) => art[name] || '').join('|')]);

    useEffect(() => {
        const el = viewportRef.current;
        if (!el || !el.clientWidth || !el.clientHeight) return;
        const saved = gridScale;
        gridScale = 1;
        const base = mapSize();
        gridScale = saved;
        const fitted = Math.max(el.clientWidth / base.width, el.clientHeight / base.height) || 1;
        const { width, height } = mapSize();
        const cover = Math.max(el.clientWidth / width, el.clientHeight / height) || 1;
        baseFit.current = cover;
        const layoutKey = `${scene?.id}:${square ? 'square' : 'hex'}:${cols}:${rows}`;
        if (fittedFor.current !== layoutKey) {
            fittedFor.current = layoutKey;
            commitView({
                zoom: fitted,
                x: (el.clientWidth - base.width * fitted) / 2,
                y: (el.clientHeight - base.height * fitted) / 2,
            });
        }
    }, [scene?.id, cols, rows, square, scale]);

    useEffect(() => {
        const el = viewportRef.current;
        if (!el) return;
        const onWheel = (event) => {
            event.preventDefault();
            const rect = el.getBoundingClientRect();
            const mx = event.clientX - rect.left;
            const my = event.clientY - rect.top;
            const current = viewRef.current;
            const factor = event.deltaY < 0 ? 1.12 : 1 / 1.12;
            const zoom = Math.min(baseFit.current * 8, Math.max(baseFit.current, current.zoom * factor));
            const ratio = zoom / current.zoom;
            commitView({
                zoom,
                x: mx - (mx - current.x) * ratio,
                y: my - (my - current.y) * ratio,
            });
        };
        el.addEventListener('wheel', onWheel, { passive: false });
        return () => el.removeEventListener('wheel', onWheel);
    }, [scene?.id, cols, rows, square, scale]);

    useEffect(() => {
        const canvas = ref.current;
        const el = viewportRef.current;
        if (!canvas || !el) return;
        const dpr = window.devicePixelRatio || 1;
        const cssWidth = el.clientWidth;
        const cssHeight = el.clientHeight;
        canvas.width = Math.max(1, Math.floor(cssWidth * dpr));
        canvas.height = Math.max(1, Math.floor(cssHeight * dpr));
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#070708';
        ctx.fillRect(0, 0, cssWidth, cssHeight);
        ctx.setTransform(dpr * view.zoom, 0, 0, dpr * view.zoom, dpr * view.x, dpr * view.y);
        const background = backgrounds.current[settings.background];
        if (background?.naturalWidth) {
            const saved = gridScale;
            gridScale = 1;
            const { width, height } = mapSize();
            gridScale = saved;
            ctx.drawImage(background, 0, 0, width, height);
        }

        const offset = gridOffset();
        ctx.save();
        ctx.translate(offset.x, offset.y);

        for (let row = 0; row < rows; row += 1) {
            for (let col = 0; col < cols; col += 1) {
                const points = polygon(col, row, square);
                const key = `${col},${row}`;
                ctx.beginPath();
                points.forEach(([x, y], index) => (index === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y)));
                ctx.closePath();
                const icon = blockIcons.current[terrain[key]];
                if (terrain[key] === 'black') {
                    ctx.fillStyle = '#070708';
                    ctx.fill();
                    if (showGrid) {
                        ctx.strokeStyle = 'rgba(197,198,200,0.28)';
                        ctx.stroke();
                    }
                } else if (icon?.naturalWidth) {
                    const { x, y } = center(col, row, square);
                    const width = square ? cellSize() : hexSize() * Math.sqrt(3);
                    const height = square ? cellSize() : hexSize() * 2;
                    ctx.save();
                    ctx.clip();
                    ctx.drawImage(icon, x - width / 2, y - height / 2, width, height);
                    ctx.restore();
                    if (showGrid) {
                        ctx.strokeStyle = 'rgba(197,198,200,0.28)';
                        ctx.stroke();
                    }
                } else if (move.has(key)) {
                    ctx.fillStyle = '#3a241c';
                    ctx.fill();
                    if (showGrid) {
                        ctx.strokeStyle = los.has(key) ? '#8f3140' : 'rgba(197,198,200,0.28)';
                        ctx.stroke();
                    }
                } else if (showGrid) {
                    ctx.strokeStyle = los.has(key) ? '#8f3140' : (settings.background ? 'rgba(197,198,200,0.35)' : 'rgba(197,198,200,0.18)');
                    ctx.stroke();
                }
            }
        }

        const fogOn = settings.fog === true;
        const explored = new Set(settings.explored || []);
        const fogged = new Set(settings.fogged || []);
        if (!gmVision && settings.lighting !== 'day') {
            const { width, height } = mapSize();
            const w = Math.max(1, Math.ceil(width));
            const h = Math.max(1, Math.ceil(height));
            if (!shade.current) shade.current = document.createElement('canvas');
            const layer = shade.current;
            if (layer.width !== w || layer.height !== h) {
                layer.width = w;
                layer.height = h;
            }
            const shadeCtx = layer.getContext('2d');
            shadeCtx.setTransform(1, 0, 0, 1, 0, 0);
            shadeCtx.globalCompositeOperation = 'source-over';
            shadeCtx.clearRect(0, 0, w, h);
            shadeCtx.fillStyle = settings.lighting === 'twilight' ? 'rgba(7,7,8,0.5)' : '#070708';
            shadeCtx.fillRect(0, 0, w, h);
            shadeCtx.globalCompositeOperation = 'destination-out';
            const feet = scene.feet_per_cell || 5;
            const step = square ? cellSize() : hexSize() * Math.sqrt(3);
            lights.filter((source) => source.enabled !== false).forEach((source) => {
                const at = lightCell(source);
                const { x, y } = center(at.x, at.y, square);
                const brightPx = Math.max(step * 0.55, ((source.bright_radius_ft || 20) / feet) * step);
                const reachPx = lightReach(source);
                const gradient = shadeCtx.createRadialGradient(x, y, 0, x, y, reachPx);
                const brightStop = Math.min(0.78, brightPx / reachPx);
                gradient.addColorStop(0, 'rgba(0,0,0,1)');
                gradient.addColorStop(brightStop * 0.42, 'rgba(0,0,0,0.96)');
                gradient.addColorStop(brightStop, 'rgba(0,0,0,0.55)');
                gradient.addColorStop(1, 'rgba(0,0,0,0)');
                shadeCtx.fillStyle = gradient;
                shadeCtx.beginPath();
                shadeCtx.arc(x, y, reachPx, 0, Math.PI * 2);
                shadeCtx.fill();
            });
            paintNightvision(shadeCtx);
            ctx.drawImage(layer, 0, 0);
        }

        const viewers = tokens.map((token) => (ghost?.id === token.id ? ghost : token));
        if (!gmVision) {
            for (let row = 0; row < rows; row += 1) {
                for (let col = 0; col < cols; col += 1) {
                    const key = `${col},${row}`;
                    const inFog = fogged.has(key) || (fogOn && !explored.has(key));
                    const unseen = viewers.length > 0 && !viewers.some((origin) => !blockedLos(origin, { x: col, y: row }, los, square));
                    if (!inFog && !unseen) continue;
                    const points = polygon(col, row, square);
                    ctx.beginPath();
                    points.forEach(([x, y], index) => (index === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y)));
                    ctx.closePath();
                    ctx.fillStyle = '#070708';
                    ctx.fill();
                }
            }
        }

        const drawTurnMark = (left, base) => {
            const icon = turnIcon.current;
            if (!icon?.naturalWidth) return;
            const height = 28 * gridScale;
            const width = height * (icon.naturalWidth / icon.naturalHeight);
            ctx.drawImage(icon, left, base - height, width, height);
        };

        tokens.forEach((token) => {
            if (npcHidden(token) || npcInFog(token)) return;
            const dragging = ghost?.id === token.id;
            const at = dragging ? ghost : token;
            const { x, y } = center(at.x, at.y, square);
            const active = token.combatant_id != null && Number(token.combatant_id) === Number(activeCombatantId);
            const image = token.portrait_url ? portraits.current.get(token.portrait_url) : null;
            if (image?.naturalWidth) {
                const boxWidth = square ? cellSize() : hexSize() * Math.sqrt(3);
                const boxHeight = square ? cellSize() : hexSize() * 2;
                const large = (token.footprint || 1) >= 2;
                const scale = large
                    ? (boxWidth * 2) / image.naturalWidth
                    : (boxHeight * 1.5 * (Number(token.portrait_scale) || 1)) / image.naturalHeight;
                const width = image.naturalWidth * scale;
                const height = image.naturalHeight * scale;
                const footY = square ? y + cellSize() / 2 : y + hexSize();
                ctx.save();
                ctx.shadowColor = 'rgba(0, 0, 0, 0.85)';
                ctx.shadowBlur = 18 * gridScale;
                ctx.shadowOffsetX = 5 * gridScale;
                ctx.shadowOffsetY = 14 * gridScale;
                ctx.drawImage(image, x - width / 2, footY - height, width, height);
                ctx.restore();
                if (active) drawTurnMark(x + width / 2 + 4 * gridScale, footY);
            } else {
                const radius = hexSize() * 0.93;
                ctx.save();
                ctx.shadowColor = 'rgba(0, 0, 0, 0.85)';
                ctx.shadowBlur = 12 * gridScale;
                ctx.shadowOffsetX = 4 * gridScale;
                ctx.shadowOffsetY = 8 * gridScale;
                ctx.beginPath();
                ctx.arc(x, y, radius, 0, Math.PI * 2);
                ctx.fillStyle = dragging ? '#1c1914' : '#12110f';
                ctx.fill();
                ctx.restore();
                ctx.fillStyle = '#e7d7b8';
                ctx.font = `${Math.round(16 * gridScale)}px Cormorant Garamond, serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(token.label || '?', x, y);
                ctx.beginPath();
                ctx.arc(x, y, radius, 0, Math.PI * 2);
                ctx.lineWidth = 1;
                ctx.strokeStyle = '#e7d7b8';
                ctx.stroke();
                if (active) drawTurnMark(x + radius + 4 * gridScale, y + radius);
            }
        });
        stroke.forEach((cell) => {
            const points = polygon(cell.x, cell.y, square);
            ctx.beginPath();
            points.forEach(([x, y], index) => (index === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y)));
            ctx.closePath();
            ctx.fillStyle = tool === 'fog' || (tool === 'both' && blockIcon === 'black') ? 'rgba(7,7,8,0.85)' : (tool === 'los' ? 'rgba(143,49,64,0.45)' : 'rgba(58,36,28,0.55)');
            ctx.fill();
        });
        lights.forEach((source) => {
            const at = lightCell(source);
            const { x, y } = center(at.x, at.y, square);
            const image = lightIcon.current;
            const height = (square ? cellSize() : hexSize() * 2) * 0.6;
            if (image?.naturalWidth) {
                const width = height * (image.naturalWidth / image.naturalHeight);
                ctx.drawImage(image, x - width / 2, y - height / 2, width, height);
            } else {
                ctx.fillStyle = '#e7d7b8';
                ctx.fillRect(x - height / 2, y - height / 2, height, height);
            }
        });
        if (measure?.from && measure?.to) {
            const cells = lineCells(measure.from, measure.to, square);
            const feet = distance(measure.from, measure.to, square) * (Number(scene?.feet_per_cell) || 5);
            cells.forEach((cell) => {
                const points = polygon(cell.x, cell.y, square);
                ctx.beginPath();
                points.forEach(([px, py], index) => (index === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)));
                ctx.closePath();
                ctx.fillStyle = 'rgba(231, 215, 184, 0.35)';
                ctx.fill();
            });
            const at = center(measure.to.x, measure.to.y, square);
            const label = `${feet} ft`;
            ctx.font = `${Math.round(14 * gridScale)}px Cormorant Garamond, serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            const width = ctx.measureText(label).width;
            const top = at.y - (square ? cellSize() : hexSize()) - 6 * gridScale;
            ctx.fillStyle = 'rgba(7, 7, 8, 0.85)';
            ctx.fillRect(at.x - width / 2 - 6, top - 18 * gridScale, width + 12, 18 * gridScale);
            ctx.fillStyle = '#e7d7b8';
            ctx.fillText(label, at.x, top);
        }
        ctx.restore();
    }, [scene, tokens, ghost, activeCombatantId, iconReady, turnReady, blocksReady, backgroundTick, portraitTick, view, stroke, tool, blockIcon, gmVision, scale, measure]);

    useEffect(() => {
        if (tool !== 'measure') setMeasure(null);
    }, [tool]);

    useEffect(() => {
        if (!ghost || drag.current) return;
        const token = tokens.find((row) => row.id === ghost.id);
        if (token && token.x === ghost.x && token.y === ghost.y) {
            setGhost(null);
        }
    }, [tokens, ghost]);

    useEffect(() => {
        tokens.forEach((token) => {
            const src = token.portrait_url;
            if (!src || portraits.current.has(src)) return;
            const image = new Image();
            portraits.current.set(src, image);
            image.onload = () => setPortraitTick((tick) => tick + 1);
            image.src = src;
        });
    }, [tokens]);

    function allowed(token) {
        if (!token) return false;
        const controls = isGm || Number(token.combatant?.controller_user_id) === Number(userId);
        if (!controls) return false;
        if (isGm) return true;
        if (movement === 'locked') return false;
        if (movement === 'turn') return token.combatant_id != null && Number(token.combatant_id) === Number(activeCombatantId);
        return true;
    }

    function hexAt(event) {
        const rect = viewportRef.current.getBoundingClientRect();
        const { zoom, x, y } = viewRef.current;
        const offset = gridOffset();
        const px = (event.clientX - rect.left - x) / zoom - offset.x;
        const py = (event.clientY - rect.top - y) / zoom - offset.y;
        let best = null;
        let bestDist = square ? cellSize() * 0.72 : hexSize() * 1.1;
        for (let row = 0; row < rows; row += 1) {
            for (let col = 0; col < cols; col += 1) {
                const point = center(col, row, square);
                const dist = Math.hypot(point.x - px, point.y - py);
                if (dist < bestDist) {
                    best = { x: col, y: row };
                    bestDist = dist;
                }
            }
        }
        return best;
    }

    function tokenAt(event) {
        const hex = hexAt(event);
        if (!hex) return null;
        return tokens.find((row) => {
            const at = ghost?.id === row.id ? ghost : row;
            return at.x === hex.x && at.y === hex.y;
        }) || null;
    }

    function pointerDown(event) {
        const hex = hexAt(event);
        if (tool === 'measure' && hex) {
            measureDrag.current = true;
            drag.current = null;
            pan.current = null;
            setHover(null);
            setMeasure({ from: hex, to: hex });
            event.currentTarget.setPointerCapture(event.pointerId);
            return;
        }
        const token = hex ? tokens.find((row) => row.x === hex.x && row.y === hex.y) : null;
        const fill = isGm && ['los', 'both', 'fog', 'erase'].includes(tool);
        if (fill && hex) {
            paintStroke.current = { cells: [hex], seen: new Set([`${hex.x},${hex.y}`]) };
            drag.current = null;
            pan.current = null;
            setHover(null);
            setStroke([hex]);
            event.currentTarget.setPointerCapture(event.pointerId);
            return;
        }
        const painting = isGm && tool !== 'move';
        if (token && allowed(token) && !painting) {
            drag.current = token;
            pan.current = null;
            setHover(null);
            setGhost({ id: token.id, x: token.x, y: token.y });
            event.currentTarget.setPointerCapture(event.pointerId);
            return;
        }
        pan.current = { px: event.clientX, py: event.clientY, x: viewRef.current.x, y: viewRef.current.y, moved: false };
        event.currentTarget.setPointerCapture(event.pointerId);
    }

    function pointerMove(event) {
        if (measureDrag.current) {
            const hex = hexAt(event);
            if (!hex) return;
            setMeasure((current) => (current ? { ...current, to: hex } : current));
            return;
        }
        if (paintStroke.current) {
            const hex = hexAt(event);
            if (!hex) return;
            const key = `${hex.x},${hex.y}`;
            if (paintStroke.current.seen.has(key)) return;
            paintStroke.current.seen.add(key);
            paintStroke.current.cells.push(hex);
            setStroke(paintStroke.current.cells.slice());
            return;
        }
        if (drag.current) {
            const hex = hexAt(event);
            if (!hex || pathBlocked(drag.current, hex, move, square)) return;
            setGhost({ id: drag.current.id, x: hex.x, y: hex.y });
            return;
        }
        if (pan.current) {
            const dx = event.clientX - pan.current.px;
            const dy = event.clientY - pan.current.py;
            if (Math.hypot(dx, dy) > 4) pan.current.moved = true;
            if (pan.current.moved) {
                setHover(null);
                commitView({ zoom: viewRef.current.zoom, x: pan.current.x + dx, y: pan.current.y + dy });
            }
            return;
        }
        const token = tokenAt(event);
        const rect = event.currentTarget.getBoundingClientRect();
        if (token?.name && !npcHidden(token) && !npcInFog(token)) {
            setHover({ name: token.name, x: event.clientX - rect.left, y: event.clientY - rect.top });
        } else {
            setHover((current) => (current ? null : current));
        }
    }

    function pointerUp(event) {
        if (measureDrag.current) {
            measureDrag.current = false;
            const hex = hexAt(event);
            if (hex) setMeasure((current) => (current ? { ...current, to: hex } : current));
            return;
        }
        if (paintStroke.current) {
            const cells = paintStroke.current.cells;
            paintStroke.current = null;
            setStroke([]);
            if (cells.length && scene) {
                router.post(`/scenes/${scene.id}/paint`, {
                    cells,
                    mode: tool,
                    terrain: tool === 'both' ? blockIcon : null,
                }, { preserveScroll: true });
            }
            return;
        }
        const token = drag.current;
        const movedView = pan.current?.moved;
        const hex = hexAt(event);
        drag.current = null;
        pan.current = null;
        if (token && hex && (hex.x !== token.x || hex.y !== token.y) && !pathBlocked(token, hex, move, square)) {
            setGhost({ id: token.id, x: hex.x, y: hex.y });
            router.patch(`/tokens/${token.id}`, { x: hex.x, y: hex.y }, {
                preserveScroll: true,
                onError: () => setGhost(null),
            });
            return;
        }
        setGhost(null);
        if (token || movedView) return;
        paint(hex);
    }

    function paint(best) {
        if (!best || !isGm || tool === 'move') return;
        if (['los', 'both', 'erase'].includes(tool)) {
            router.post(`/scenes/${scene.id}/paint`, {
                ...best,
                mode: tool,
                terrain: tool === 'both' ? blockIcon : null,
            }, { preserveScroll: true });
        } else if (tool === 'light') {
            router.post(`/scenes/${scene.id}/lights`, best, { preserveScroll: true });
        }
    }

    return (
        <div ref={viewportRef} className="relative h-[70vh] w-full overflow-hidden border border-line bg-ink">
            <canvas
                ref={ref}
                onPointerDown={pointerDown}
                onPointerMove={pointerMove}
                onPointerUp={pointerUp}
                onPointerLeave={() => { if (!drag.current && !pan.current) setHover(null); }}
                className={`absolute inset-0 h-full w-full touch-none ${tool === 'measure' ? 'cursor-crosshair' : 'cursor-grab active:cursor-grabbing'}`}
            />
            {hover && (
                <span role="tooltip" className="pointer-events-none absolute z-30 -translate-x-1/2 -translate-y-full border border-line bg-ink px-2 py-1 text-xs text-parchment shadow-xl" style={{ left: hover.x, top: hover.y - 8 }}>
                    {hover.name}
                </span>
            )}
        </div>
    );
}
