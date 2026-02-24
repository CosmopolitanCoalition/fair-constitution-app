"""
languages.py — Static ISO3 country code → official language(s) mapping.

Returns ISO 639-1 language codes suitable for the jurisdictions.official_languages
JSON column (default '["en"]').

Sources: UN official language lists, Wikipedia, national constitutions.
This is a best-effort mapping for the top language(s) per country.
Sub-national jurisdictions inherit from their parent country's setting.
"""

import json

# ─── Per-country language mapping (ISO 3166-1 alpha-3 → ISO 639-1 list) ──────

ISO3_LANGUAGES: dict[str, list[str]] = {
    "ABW": ["nl", "pap"],       # Aruba
    "AFG": ["ps", "uz", "tk"],  # Afghanistan
    "AGO": ["pt"],              # Angola
    "AIA": ["en"],              # Anguilla
    "ALB": ["sq"],              # Albania
    "AND": ["ca"],              # Andorra
    "ARE": ["ar"],              # UAE
    "ARG": ["es"],              # Argentina
    "ARM": ["hy"],              # Armenia
    "ASM": ["en", "sm"],        # American Samoa
    "ATA": [],                  # Antarctica (no official language)
    "ATG": ["en"],              # Antigua and Barbuda
    "AUS": ["en"],              # Australia
    "AUT": ["de"],              # Austria
    "AZE": ["az"],              # Azerbaijan
    "BDI": ["fr", "rn"],        # Burundi
    "BEL": ["nl", "fr", "de"],  # Belgium
    "BEN": ["fr"],              # Benin
    "BES": ["nl"],              # Bonaire, Sint Eustatius and Saba
    "BFA": ["fr"],              # Burkina Faso
    "BGD": ["bn"],              # Bangladesh
    "BGR": ["bg"],              # Bulgaria
    "BHR": ["ar"],              # Bahrain
    "BHS": ["en"],              # Bahamas
    "BIH": ["bs", "hr", "sr"],  # Bosnia and Herzegovina
    "BLM": ["fr"],              # Saint Barthélemy
    "BLR": ["be", "ru"],        # Belarus
    "BLZ": ["en"],              # Belize
    "BMU": ["en"],              # Bermuda
    "BOL": ["es", "qu", "ay"],  # Bolivia
    "BRA": ["pt"],              # Brazil
    "BRB": ["en"],              # Barbados
    "BRN": ["ms"],              # Brunei
    "BTN": ["dz"],              # Bhutan
    "BWA": ["en", "tn"],        # Botswana
    "CAF": ["fr", "sg"],        # Central African Republic
    "CAN": ["en", "fr"],        # Canada
    "CHE": ["de", "fr", "it", "rm"],  # Switzerland
    "CHL": ["es"],              # Chile
    "CHN": ["zh"],              # China
    "CIV": ["fr"],              # Côte d'Ivoire
    "CMR": ["fr", "en"],        # Cameroon
    "COD": ["fr"],              # DR Congo
    "COG": ["fr"],              # Republic of Congo
    "COK": ["en", "mi"],        # Cook Islands
    "COL": ["es"],              # Colombia
    "COM": ["ar", "fr"],        # Comoros
    "CPV": ["pt"],              # Cape Verde
    "CRI": ["es"],              # Costa Rica
    "CUB": ["es"],              # Cuba
    "CUW": ["nl", "pap"],       # Curaçao
    "CYM": ["en"],              # Cayman Islands
    "CYP": ["el", "tr"],        # Cyprus
    "CZE": ["cs"],              # Czech Republic
    "DEU": ["de"],              # Germany
    "DJI": ["fr", "ar"],        # Djibouti
    "DMA": ["en"],              # Dominica
    "DNK": ["da"],              # Denmark
    "DOM": ["es"],              # Dominican Republic
    "DZA": ["ar"],              # Algeria
    "ECU": ["es"],              # Ecuador
    "EGY": ["ar"],              # Egypt
    "ERI": ["ti", "ar", "en"],  # Eritrea
    "ESP": ["es"],              # Spain
    "EST": ["et"],              # Estonia
    "ETH": ["am"],              # Ethiopia
    "FIN": ["fi", "sv"],        # Finland
    "FJI": ["en", "fj", "hi"],  # Fiji
    "FLK": ["en"],              # Falkland Islands
    "FRA": ["fr"],              # France
    "FRO": ["fo", "da"],        # Faroe Islands
    "FSM": ["en"],              # Micronesia
    "GAB": ["fr"],              # Gabon
    "GBR": ["en"],              # United Kingdom
    "GEO": ["ka"],              # Georgia
    "GGY": ["en", "fr"],        # Guernsey
    "GHA": ["en"],              # Ghana
    "GIB": ["en"],              # Gibraltar
    "GIN": ["fr"],              # Guinea
    "GLP": ["fr"],              # Guadeloupe
    "GMB": ["en"],              # Gambia
    "GNB": ["pt"],              # Guinea-Bissau
    "GNQ": ["es", "fr", "pt"],  # Equatorial Guinea
    "GRC": ["el"],              # Greece
    "GRD": ["en"],              # Grenada
    "GRL": ["kl", "da"],        # Greenland
    "GTM": ["es"],              # Guatemala
    "GUF": ["fr"],              # French Guiana
    "GUM": ["en", "ch"],        # Guam
    "GUY": ["en"],              # Guyana
    "HND": ["es"],              # Honduras
    "HRV": ["hr"],              # Croatia
    "HTI": ["fr", "ht"],        # Haiti
    "HUN": ["hu"],              # Hungary
    "IDN": ["id"],              # Indonesia
    "IMN": ["en", "gv"],        # Isle of Man
    "IND": ["hi", "en"],        # India
    "IRL": ["ga", "en"],        # Ireland
    "IRN": ["fa"],              # Iran
    "IRQ": ["ar", "ku"],        # Iraq
    "ISL": ["is"],              # Iceland
    "ISR": ["he", "ar"],        # Israel
    "ITA": ["it"],              # Italy
    "JAM": ["en"],              # Jamaica
    "JOR": ["ar"],              # Jordan
    "JPN": ["ja"],              # Japan
    "KAZ": ["kk", "ru"],        # Kazakhstan
    "KEN": ["sw", "en"],        # Kenya
    "KGZ": ["ky", "ru"],        # Kyrgyzstan
    "KHM": ["km"],              # Cambodia
    "KIR": ["en"],              # Kiribati
    "KNA": ["en"],              # Saint Kitts and Nevis
    "KOR": ["ko"],              # South Korea
    "KWT": ["ar"],              # Kuwait
    "LAO": ["lo"],              # Laos
    "LBN": ["ar", "fr"],        # Lebanon
    "LBR": ["en"],              # Liberia
    "LBY": ["ar"],              # Libya
    "LCA": ["en"],              # Saint Lucia
    "LIE": ["de"],              # Liechtenstein
    "LKA": ["si", "ta"],        # Sri Lanka
    "LSO": ["st", "en"],        # Lesotho
    "LTU": ["lt"],              # Lithuania
    "LUX": ["lb", "fr", "de"],  # Luxembourg
    "LVA": ["lv"],              # Latvia
    "MAR": ["ar", "ber"],       # Morocco
    "MCO": ["fr"],              # Monaco
    "MDA": ["ro"],              # Moldova
    "MDG": ["mg", "fr"],        # Madagascar
    "MDV": ["dv"],              # Maldives
    "MEX": ["es"],              # Mexico
    "MHL": ["mh", "en"],        # Marshall Islands
    "MKD": ["mk", "sq"],        # North Macedonia
    "MLI": ["fr"],              # Mali
    "MLT": ["mt", "en"],        # Malta
    "MMR": ["my"],              # Myanmar
    "MNE": ["sr", "bs", "sq", "hr"],  # Montenegro
    "MNG": ["mn"],              # Mongolia
    "MNP": ["en"],              # Northern Mariana Islands
    "MOZ": ["pt"],              # Mozambique
    "MRT": ["ar"],              # Mauritania
    "MSR": ["en"],              # Montserrat
    "MTQ": ["fr"],              # Martinique
    "MUS": ["en", "fr"],        # Mauritius
    "MWI": ["en", "ny"],        # Malawi
    "MYS": ["ms"],              # Malaysia
    "MYT": ["fr"],              # Mayotte
    "NAM": ["en", "af"],        # Namibia
    "NCL": ["fr"],              # New Caledonia
    "NER": ["fr"],              # Niger
    "NGA": ["en"],              # Nigeria
    "NIC": ["es"],              # Nicaragua
    "NIU": ["en"],              # Niue
    "NLD": ["nl"],              # Netherlands
    "NOR": ["no", "nb", "nn"],  # Norway
    "NPL": ["ne"],              # Nepal
    "NRU": ["na", "en"],        # Nauru
    "NZL": ["en", "mi"],        # New Zealand
    "OMN": ["ar"],              # Oman
    "PAK": ["ur", "en"],        # Pakistan
    "PAN": ["es"],              # Panama
    "PCN": ["en"],              # Pitcairn Islands
    "PER": ["es", "qu", "ay"],  # Peru
    "PHL": ["fil", "en"],       # Philippines
    "PLW": ["pau", "en"],       # Palau
    "PNG": ["en", "tpi", "ho"], # Papua New Guinea
    "POL": ["pl"],              # Poland
    "PRI": ["es", "en"],        # Puerto Rico
    "PRK": ["ko"],              # North Korea
    "PRT": ["pt"],              # Portugal
    "PRY": ["es", "gn"],        # Paraguay
    "PSE": ["ar"],              # Palestine
    "PYF": ["fr"],              # French Polynesia
    "QAT": ["ar"],              # Qatar
    "REU": ["fr"],              # Réunion
    "ROU": ["ro"],              # Romania
    "RUS": ["ru"],              # Russia
    "RWA": ["rw", "fr", "en"],  # Rwanda
    "SAU": ["ar"],              # Saudi Arabia
    "SDN": ["ar", "en"],        # Sudan
    "SEN": ["fr"],              # Senegal
    "SGP": ["en", "ms", "zh", "ta"],  # Singapore
    "SHN": ["en"],              # Saint Helena
    "SLB": ["en"],              # Solomon Islands
    "SLE": ["en"],              # Sierra Leone
    "SLV": ["es"],              # El Salvador
    "SMR": ["it"],              # San Marino
    "SOM": ["so", "ar"],        # Somalia
    "SRB": ["sr"],              # Serbia
    "SSD": ["en", "ar"],        # South Sudan
    "STP": ["pt"],              # São Tomé and Príncipe
    "SUR": ["nl"],              # Suriname
    "SVK": ["sk"],              # Slovakia
    "SVN": ["sl"],              # Slovenia
    "SWE": ["sv"],              # Sweden
    "SWZ": ["ss", "en"],        # Eswatini
    "SYC": ["fr", "en", "cr"],  # Seychelles
    "SYR": ["ar"],              # Syria
    "TCA": ["en"],              # Turks and Caicos Islands
    "TCD": ["fr", "ar"],        # Chad
    "TGO": ["fr"],              # Togo
    "THA": ["th"],              # Thailand
    "TJK": ["tg", "ru"],        # Tajikistan
    "TKL": ["en"],              # Tokelau
    "TKM": ["tk"],              # Turkmenistan
    "TLS": ["pt", "tet"],       # Timor-Leste
    "TON": ["to", "en"],        # Tonga
    "TTO": ["en"],              # Trinidad and Tobago
    "TUN": ["ar"],              # Tunisia
    "TUR": ["tr"],              # Turkey
    "TUV": ["en"],              # Tuvalu
    "TWN": ["zh"],              # Taiwan
    "TZA": ["sw", "en"],        # Tanzania
    "UGA": ["en", "sw"],        # Uganda
    "UKR": ["uk"],              # Ukraine
    "URY": ["es"],              # Uruguay
    "USA": ["en"],              # United States
    "UZB": ["uz"],              # Uzbekistan
    "VAT": ["it", "la"],        # Vatican City
    "VCT": ["en"],              # Saint Vincent and the Grenadines
    "VEN": ["es"],              # Venezuela
    "VGB": ["en"],              # British Virgin Islands
    "VIR": ["en"],              # US Virgin Islands
    "VNM": ["vi"],              # Vietnam
    "VUT": ["bi", "en", "fr"],  # Vanuatu
    "WLF": ["fr"],              # Wallis and Futuna
    "WSM": ["sm", "en"],        # Samoa
    "XKX": ["sq", "sr"],        # Kosovo
    "YEM": ["ar"],              # Yemen
    "ZAF": ["zu", "xh", "af", "en", "st", "tn", "ts", "ss", "ve", "nr", "nd"],  # South Africa
    "ZMB": ["en"],              # Zambia
    "ZWE": ["en", "sn", "nd"],  # Zimbabwe
}

# ─── Region fallbacks (UN subregion from geoBoundaries meta.csv) ─────────────

REGION_FALLBACKS: dict[str, list[str]] = {
    "Sub-Saharan Africa":                    ["fr"],
    "Northern Africa":                       ["ar"],
    "Western Africa":                        ["fr"],
    "Middle Africa":                         ["fr"],
    "Eastern Africa":                        ["en"],
    "Southern Africa":                       ["en"],
    "Western Asia":                          ["ar"],
    "Central Asia":                          ["ru"],
    "Eastern Asia":                          ["zh"],
    "South-Eastern Asia":                    ["en"],
    "Southern Asia":                         ["en"],
    "Eastern Europe":                        ["ru"],
    "Western Europe":                        ["fr"],
    "Northern Europe":                       ["en"],
    "Southern Europe":                       ["es"],
    "Latin America and the Caribbean":       ["es"],
    "Caribbean":                             ["en"],
    "Northern America":                      ["en"],
    "Australia and New Zealand":             ["en"],
    "Melanesia":                             ["en"],
    "Micronesia":                            ["en"],
    "Polynesia":                             ["en"],
}


def get_languages(iso3: str, unsdg_region: str = "") -> str:
    """
    Return a JSON array string of ISO 639-1 language codes for a country.

    Lookup priority:
      1. Exact ISO3 match in ISO3_LANGUAGES
      2. UN subregion fallback in REGION_FALLBACKS
      3. Hard default: ["en"]

    Args:
        iso3:         ISO 3166-1 alpha-3 country code (e.g. "USA")
        unsdg_region: UN subregion string from geoBoundaries meta.csv
                      (e.g. "Northern America") — used as fallback only

    Returns:
        JSON string e.g. '["en"]' or '["fr", "nl"]'
    """
    if iso3 in ISO3_LANGUAGES:
        langs = ISO3_LANGUAGES[iso3]
        return json.dumps(langs) if langs else '["en"]'

    if unsdg_region and unsdg_region in REGION_FALLBACKS:
        return json.dumps(REGION_FALLBACKS[unsdg_region])

    return '["en"]'
