/**
 * Tiện ích vùng chọn mask → crop ảnh (dùng Magic Eraser → Image Splitter).
 */

export function getMaskBoundingBox(maskCanvas) {
    if (!maskCanvas) {
        return null;
    }

    const { width, height } = maskCanvas;
    if (width === 0 || height === 0) {
        return null;
    }

    const ctx = maskCanvas.getContext('2d');
    const data = ctx.getImageData(0, 0, width, height).data;

    let minX = width;
    let minY = height;
    let maxX = -1;
    let maxY = -1;

    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
            const alpha = data[(y * width + x) * 4 + 3];
            if (alpha > 0) {
                if (x < minX) minX = x;
                if (y < minY) minY = y;
                if (x > maxX) maxX = x;
                if (y > maxY) maxY = y;
            }
        }
    }

    if (maxX < 0 || maxY < 0) {
        return null;
    }

    const w = maxX - minX + 1;
    const h = maxY - minY + 1;

    return {
        x: minX,
        y: minY,
        width: Math.max(1, w),
        height: Math.max(1, h),
    };
}

export async function cropCanvasRegion(sourceCanvas, bbox) {
    const { x, y, width, height } = bbox;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(sourceCanvas, x, y, width, height, 0, 0, width, height);

    const blob = await new Promise((resolve) => {
        canvas.toBlob((b) => resolve(b), 'image/png', 1);
    });

    if (!blob) {
        throw new Error('Không tạo được ảnh từ vùng chọn.');
    }

    return {
        width,
        height,
        blob,
        url: URL.createObjectURL(blob),
    };
}

export async function createPieceFromSelection(imageCanvas, maskCanvas, imageName = 'image') {
    const bbox = getMaskBoundingBox(maskCanvas);
    if (!bbox) {
        throw new Error('Chưa có vùng chọn hợp lệ.');
    }

    const cropped = await cropCanvasRegion(imageCanvas, bbox);

    return {
        id: `selection-${Date.now()}`,
        row: 1,
        col: 1,
        width: cropped.width,
        height: cropped.height,
        blob: cropped.blob,
        url: cropped.url,
        filename: `${imageName}-selection.png`,
        source: 'selection',
    };
}
