/**
 * The shortcode repository.
 *
 * Bodies written by people carry real glyphs — the composer's picker inserts
 * the character itself. Bodies written by *machines* do not: a GitHub webhook
 * says `:rocket:` and a CI bot says `:white_check_mark:`, because the Slack
 * integrations they were built against resolve shortcodes at render time. This
 * map is how Snack resolves them, and it is the reason those messages read as
 * pictures rather than as punctuation.
 *
 * It is deliberately a fixed table rather than a package. The full emoji set is
 * a megabyte of data to render a dozen glyphs a webhook actually sends, and a
 * shortcode nobody knows must stay literal text — see {@link emojiFor} — so the
 * cost of a miss is a shortcode you can still read, not a blank square.
 *
 * Aliases share a glyph on purpose: senders disagree about whether it is
 * `thumbsup` or `+1`, and both should draw a thumb.
 */
export const EMOJI: Record<string, string> = {
    // Hands and people. The vocabulary of an approving reply.
    '+1': '👍',
    '-1': '👎',
    thumbsup: '👍',
    thumbsdown: '👎',
    ok_hand: '👌',
    clap: '👏',
    raised_hands: '🙌',
    pray: '🙏',
    handshake: '🤝',
    muscle: '💪',
    wave: '👋',
    point_right: '👉',
    point_left: '👈',
    point_up: '☝️',
    point_down: '👇',
    eyes: '👀',
    brain: '🧠',
    person_shrugging: '🤷',
    shrug: '🤷',
    facepalm: '🤦',

    // Faces.
    smile: '😄',
    smiley: '😃',
    grin: '😁',
    grinning: '😀',
    joy: '😂',
    rofl: '🤣',
    sweat_smile: '😅',
    wink: '😉',
    blush: '😊',
    heart_eyes: '😍',
    thinking: '🤔',
    thinking_face: '🤔',
    neutral_face: '😐',
    expressionless: '😑',
    upside_down_face: '🙃',
    sunglasses: '😎',
    nerd_face: '🤓',
    exploding_head: '🤯',
    scream: '😱',
    fearful: '😨',
    sweat: '😓',
    cry: '😢',
    sob: '😭',
    angry: '😠',
    rage: '😡',
    sleeping: '😴',
    zany_face: '🤪',
    partying_face: '🥳',
    melting_face: '🫠',
    saluting_face: '🫡',
    robot: '🤖',
    robot_face: '🤖',
    alien: '👽',
    ghost: '👻',
    skull: '💀',
    poop: '💩',
    hankey: '💩',

    // Deployment and delivery. What release bots talk about.
    rocket: '🚀',
    ship: '🚢',
    shipit: '🚢',
    truck: '🚚',
    airplane: '✈️',
    package: '📦',
    whale: '🐳',
    construction: '🚧',
    hammer: '🔨',
    wrench: '🔧',
    hammer_and_wrench: '🛠️',
    tools: '🛠️',
    nut_and_bolt: '🔩',
    gear: '⚙️',
    factory: '🏭',
    building_construction: '🏗️',

    // Version control. The vocabulary of a repository webhook.
    twisted_rightwards_arrows: '🔀',
    arrows_counterclockwise: '🔄',
    arrows_clockwise: '🔃',
    repeat: '🔁',
    recycle: '♻️',
    rewind: '⏪',
    fast_forward: '⏩',
    arrow_forward: '▶️',
    arrow_up: '⬆️',
    arrow_down: '⬇️',
    arrow_right: '➡️',
    arrow_left: '⬅️',
    arrow_heading_up: '⤴️',
    arrow_heading_down: '⤵️',
    bookmark: '🔖',
    label: '🏷️',
    scissors: '✂️',

    // Status. Half of what a build reports is one of these.
    white_check_mark: '✅',
    heavy_check_mark: '✔️',
    ballot_box_with_check: '☑️',
    x: '❌',
    negative_squared_cross_mark: '❎',
    heavy_multiplication_x: '✖️',
    warning: '⚠️',
    rotating_light: '🚨',
    no_entry: '⛔',
    no_entry_sign: '🚫',
    octagonal_sign: '🛑',
    traffic_light: '🚦',
    red_circle: '🔴',
    green_circle: '🟢',
    yellow_circle: '🟡',
    orange_circle: '🟠',
    large_blue_circle: '🔵',
    white_circle: '⚪',
    black_circle: '⚫',
    hourglass: '⌛',
    hourglass_flowing_sand: '⏳',
    stopwatch: '⏱️',
    alarm_clock: '⏰',
    clock: '🕐',
    clock1: '🕐',
    zzz: '💤',

    // Emphasis.
    fire: '🔥',
    boom: '💥',
    zap: '⚡',
    sparkles: '✨',
    sparkle: '❇️',
    star: '⭐',
    star2: '🌟',
    dizzy: '💫',
    tada: '🎉',
    confetti_ball: '🎊',
    balloon: '🎈',
    gift: '🎁',
    birthday: '🎂',
    cake: '🍰',
    trophy: '🏆',
    medal: '🏅',
    first_place_medal: '🥇',
    dart: '🎯',
    checkered_flag: '🏁',
    crown: '👑',
    gem: '💎',
    hundred: '💯',
    '100': '💯',
    bangbang: '‼️',
    interrobang: '⁉️',
    question: '❓',
    grey_question: '❔',
    exclamation: '❗',
    heavy_plus_sign: '➕',
    heavy_minus_sign: '➖',
    heavy_division_sign: '➗',
    new: '🆕',
    ok: '🆗',
    up: '🆙',
    soon: '🔜',
    free: '🆓',

    // Hearts and reactions.
    heart: '❤️',
    orange_heart: '🧡',
    yellow_heart: '💛',
    green_heart: '💚',
    blue_heart: '💙',
    purple_heart: '💜',
    black_heart: '🖤',
    white_heart: '🤍',
    broken_heart: '💔',
    sparkling_heart: '💖',

    // Work: documents, mail, meetings.
    memo: '📝',
    pencil: '✏️',
    pencil2: '✏️',
    page_facing_up: '📄',
    pages: '📑',
    clipboard: '📋',
    bookmark_tabs: '📑',
    book: '📖',
    books: '📚',
    notebook: '📓',
    ledger: '📒',
    calendar: '📅',
    date: '📅',
    spiral_calendar: '🗓️',
    chart_with_upwards_trend: '📈',
    chart_with_downwards_trend: '📉',
    bar_chart: '📊',
    email: '📧',
    envelope: '✉️',
    inbox_tray: '📥',
    outbox_tray: '📤',
    mailbox: '📫',
    paperclip: '📎',
    link: '🔗',
    mag: '🔍',
    mag_right: '🔎',
    telescope: '🔭',
    microscope: '🔬',
    test_tube: '🧪',
    dna: '🧬',
    bulb: '💡',
    key: '🔑',
    lock: '🔒',
    unlock: '🔓',
    closed_lock_with_key: '🔐',
    shield: '🛡️',
    bug: '🐛',
    beetle: '🪲',
    spider: '🕷️',
    snake: '🐍',
    elephant: '🐘',
    cat: '🐱',
    dog: '🐶',
    unicorn: '🦄',
    penguin: '🐧',
    owl: '🦉',

    // Devices and infrastructure.
    computer: '💻',
    desktop_computer: '🖥️',
    keyboard: '⌨️',
    floppy_disk: '💾',
    minidisc: '💽',
    cd: '💿',
    printer: '🖨️',
    iphone: '📱',
    phone: '☎️',
    telephone: '☎️',
    battery: '🔋',
    electric_plug: '🔌',
    satellite: '📡',
    signal_strength: '📶',
    globe_with_meridians: '🌐',
    earth_americas: '🌎',
    cloud: '☁️',
    sunny: '☀️',
    umbrella: '☂️',
    snowflake: '❄️',
    rainbow: '🌈',
    ocean: '🌊',
    seedling: '🌱',
    herb: '🌿',
    four_leaf_clover: '🍀',
    evergreen_tree: '🌲',

    // Announcements.
    mega: '📣',
    loudspeaker: '📢',
    bell: '🔔',
    no_bell: '🔕',
    speech_balloon: '💬',
    thought_balloon: '💭',
    wastebasket: '🗑️',
    fire_extinguisher: '🧯',
    broom: '🧹',
    soap: '🧼',
    magnet: '🧲',
    hammer_and_pick: '⚒️',
    crystal_ball: '🔮',
    game_die: '🎲',
    joystick: '🕹️',
    art: '🎨',
    clapper: '🎬',
    musical_note: '🎵',
    headphones: '🎧',
    camera: '📷',
    movie_camera: '🎥',
    tv: '📺',
    radio: '📻',

    // Food and drink. Standups run on these.
    coffee: '☕',
    tea: '🍵',
    beer: '🍺',
    beers: '🍻',
    wine_glass: '🍷',
    champagne: '🍾',
    cocktail: '🍸',
    pizza: '🍕',
    hamburger: '🍔',
    fries: '🍟',
    taco: '🌮',
    burrito: '🌯',
    sushi: '🍣',
    ramen: '🍜',
    doughnut: '🍩',
    cookie: '🍪',
    popcorn: '🍿',
    apple: '🍎',
    banana: '🍌',
    watermelon: '🍉',
    salt: '🧂',

    // Money.
    moneybag: '💰',
    money_with_wings: '💸',
    dollar: '💵',
    credit_card: '💳',
    receipt: '🧾',
    chart: '💹',
};

/**
 * The glyph for a shortcode, or null when nothing is known by that name.
 *
 * Null is meaningful: the message parser leaves an unknown `:shortcode:` as the
 * literal text the author wrote, because a name this build has never heard of
 * is more useful spelled out than swallowed.
 */
export function emojiFor(shortcode: string): string | null {
    return EMOJI[shortcode] ?? null;
}

/**
 * The reaction set.
 *
 * Mirrors `snack.reactions.available` in config/snack.php, which is the
 * server's allowlist. Reactions are stored as shortcodes ("tada"), never as the
 * rendered glyph, so the same reaction survives a change of emoji set — this map
 * is the only place the two are connected.
 *
 * Keep the two in step. A shortcode the server accepts but this map does not
 * know renders as a blank pill; one this map knows but the server rejects is a
 * picker button that always fails.
 */
export const REACTIONS: Record<string, string> = {
    thumbsup: '👍',
    heart: '❤️',
    tada: '🎉',
    rocket: '🚀',
    eyes: '👀',
    joy: '😂',
    thinking: '🤔',
    fire: '🔥',
    clap: '👏',
    white_check_mark: '✅',
    pray: '🙏',
    taco: '🌮',
};

/** The picker's contents, in the order they are offered. */
export const REACTION_SHORTCODES = Object.keys(REACTIONS);

/**
 * The glyph for a shortcode.
 *
 * Falls back to the shortcode itself rather than to an empty string: a reaction
 * this build does not recognize should still be visible and removable, not an
 * invisible pill that counts toward a total nobody can account for.
 */
export function emojiGlyph(shortcode: string): string {
    return REACTIONS[shortcode] ?? `:${shortcode}:`;
}

/**
 * The tooltip for a reaction pill: who reacted.
 *
 * The name list is capped server-side, so anyone beyond it is folded into a
 * count — "Ada, Bo and 3 others" — rather than being silently dropped.
 */
export function reactionTooltip(
    names: string[],
    count: number,
    shortcode: string,
): string {
    const label = `:${shortcode}:`;

    if (names.length === 0) {
        return `${count} ${count === 1 ? 'reaction' : 'reactions'} ${label}`;
    }

    const hidden = count - names.length;

    const people =
        names.length === 1
            ? names[0]
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return hidden > 0
        ? `${names.join(', ')} and ${hidden} ${hidden === 1 ? 'other' : 'others'} reacted with ${label}`
        : `${people} reacted with ${label}`;
}
