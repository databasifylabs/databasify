export type OutputPiece = { offset: number; bytes: Uint8Array };

const encoder = new TextEncoder();
const decoder = new TextDecoder();

export function pieceOf(chunk: string, offset: number): OutputPiece {
    return { offset, bytes: encoder.encode(chunk) };
}

export function stitchOutput(saved: string, pieces: OutputPiece[]): string {
    const parts: Uint8Array[] = [encoder.encode(saved)];
    let end = parts[0].length;

    for (const piece of [...pieces].sort((a, b) => a.offset - b.offset)) {
        if (piece.offset > end) {
            break;
        }

        const unseen = piece.bytes.subarray(end - piece.offset);

        if (unseen.length > 0) {
            parts.push(unseen);
            end += unseen.length;
        }
    }

    const stitched = new Uint8Array(end);
    parts.reduce((at, part) => {
        stitched.set(part, at);

        return at + part.length;
    }, 0);

    return decoder.decode(stitched);
}

export function unsavedPieces(
    saved: string,
    pieces: OutputPiece[],
): OutputPiece[] {
    const savedBytes = encoder.encode(saved).length;

    return pieces.filter(
        (piece) => piece.offset + piece.bytes.length > savedBytes,
    );
}
