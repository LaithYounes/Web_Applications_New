const photos = [
    ['Sony WH-1000XM5', 'HS You', 'https://www.flickr.com/photos/thebetterday4u/52436931175/', 'CC BY-ND 2.0', 'https://creativecommons.org/licenses/by-nd/2.0/'],
    ['Apple iPhone 15', 'ThePhotoGraphIc', 'https://commons.wikimedia.org/wiki/File:Back_of_iPhone_15.jpg', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/'],
    ['Logitech M185', 'Hayden Schiff', 'https://commons.wikimedia.org/wiki/File:Logitech_M185_mouse_HS01.jpg', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'],
    ['Apple Watch SE', 'AzureSaturn', 'https://commons.wikimedia.org/wiki/File:Apple_Watch_SE_2_(GPS_%2B_Cellular,_40mm,_Midnight)_with_Anchor_Blue_Sport_Loop.jpg', 'CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
] as const;

export const demoPhotoProducts: ReadonlySet<string> = new Set(photos.map(([name]) => name));

export function PhotoCredits() {
    return <details className="photo-credits">
        <summary>Demo photo credits</summary>
        <ul>{photos.map(([name, author, source, license, licenseUrl]) =>
            <li key={name}><a href={source}>{name} by {author}</a> · <a href={licenseUrl}>{license}</a></li>)}</ul>
    </details>;
}
