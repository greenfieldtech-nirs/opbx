// Auto-generated from Asterisk indications.conf.sample
// Do not edit manually. Regenerate with: php scripts/generate-tone-indications.php

export interface ToneElement {
  freqs: number[];
  durationMs: number;
  once?: boolean;
}

export type ToneName =
  | 'ring'
  | 'busy'
  | 'congestion'
  | 'dial'
  | 'callwaiting'
  | 'dialrecall'
  | 'record'
  | 'info'
  | 'stutter';

export interface ToneSet {
  description: string;
  ringcadence?: number[];
  tones: Record<string, ToneElement[]>;
}

export const DEFAULT_COUNTRY = 'us';

export const TONE_INDICATIONS: Record<string, ToneSet> = {
    "at": {
        "description": "Austria",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 400
                }
            ],
            "ring": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 5000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 40
                },
                {
                    "freqs": [],
                    "durationMs": 1960
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 80
                },
                {
                    "freqs": [],
                    "durationMs": 14920
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1450
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1850
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        380,
                        420
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            5000
        ]
    },
    "au": {
        "description": "Australia",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        413,
                        438
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 375
                },
                {
                    "freqs": [],
                    "durationMs": 375
                }
            ],
            "ring": [
                {
                    "freqs": [
                        413,
                        438
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        413,
                        438
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 375
                },
                {
                    "freqs": [],
                    "durationMs": 375
                },
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 375
                },
                {
                    "freqs": [],
                    "durationMs": 375
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 4400
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        413,
                        438
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 15000,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 360
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 2500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "std": [
                {
                    "freqs": [
                        525
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        525
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        525
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        525
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        525
                    ],
                    "durationMs": 100,
                    "once": true
                }
            ],
            "facility": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        413,
                        438
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 40
                }
            ],
            "ringmobile": [
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ]
        },
        "ringcadence": [
            400,
            200,
            400,
            2000
        ]
    },
    "bg": {
        "description": "Bulgaria",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 150
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 425
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1500
                },
                {
                    "freqs": [],
                    "durationMs": 100
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "br": {
        "description": "Brazil",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 50
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "be": {
        "description": "Belgium",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 3000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 167
                },
                {
                    "freqs": [],
                    "durationMs": 167
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 175
                },
                {
                    "freqs": [],
                    "durationMs": 175
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 175
                },
                {
                    "freqs": [],
                    "durationMs": 3500
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        900
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ]
        },
        "ringcadence": [
            1000,
            3000
        ]
    },
    "ch": {
        "description": "Switzerland",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 80
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425,
                        340
                    ],
                    "durationMs": 1100
                },
                {
                    "freqs": [],
                    "durationMs": 1100
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "cl": {
        "description": "Chile",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 3000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 8750
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 333
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 333
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 333
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            3000
        ]
    },
    "cn": {
        "description": "China",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 350
                },
                {
                    "freqs": [],
                    "durationMs": 350
                }
            ],
            "ring": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 700
                },
                {
                    "freqs": [],
                    "durationMs": 700
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "info": [
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 400
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        450,
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "cz": {
        "description": "Czech Republic",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 660
                },
                {
                    "freqs": [],
                    "durationMs": 660
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 330
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 165
                },
                {
                    "freqs": [],
                    "durationMs": 165
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 9000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 660
                },
                {
                    "freqs": [],
                    "durationMs": 660
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 14000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 450
                },
                {
                    "freqs": [],
                    "durationMs": 50
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "de": {
        "description": "Germany",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 480
                },
                {
                    "freqs": [],
                    "durationMs": 480
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 240
                },
                {
                    "freqs": [],
                    "durationMs": 240
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 5000,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 5000,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 5000,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 5000,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 80
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425,
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "dk": {
        "description": "Denmark",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 3000,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 80
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 450
                },
                {
                    "freqs": [],
                    "durationMs": 50
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "ee": {
        "description": "Estonia",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 300
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 325
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 325
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 1300
                },
                {
                    "freqs": [],
                    "durationMs": 2600
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 25
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 325
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 325
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 1300
                },
                {
                    "freqs": [],
                    "durationMs": 2600
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "es": {
        "description": "Spain",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1500
                },
                {
                    "freqs": [],
                    "durationMs": 3000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 600
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 175
                },
                {
                    "freqs": [],
                    "durationMs": 175
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 175
                },
                {
                    "freqs": [],
                    "durationMs": 3500
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "dialout": [
                {
                    "freqs": [
                        500
                    ],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1500,
            3000
        ]
    },
    "fi": {
        "description": "Finland",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 300
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 150
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 8000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 25
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 325
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 325
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 1300
                },
                {
                    "freqs": [],
                    "durationMs": 2600
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 25
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "fr": {
        "description": "France",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 1500
                },
                {
                    "freqs": [],
                    "durationMs": 3500
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwait": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1500,
            3500
        ]
    },
    "gr": {
        "description": "Greece",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 300
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 700
                },
                {
                    "freqs": [],
                    "durationMs": 800
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 300
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 150
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 8000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 25
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 650
                },
                {
                    "freqs": [],
                    "durationMs": 25
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "hu": {
        "description": "Hungary",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 300
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1250
                },
                {
                    "freqs": [],
                    "durationMs": 3750
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 300
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 40
                },
                {
                    "freqs": [],
                    "durationMs": 1960
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425,
                        450
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        375,
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1250,
            3750
        ]
    },
    "id": {
        "description": "Indonesia",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 150
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "il": {
        "description": "Israel",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 3000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 600
                },
                {
                    "freqs": [],
                    "durationMs": 3000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        1000
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 160,
                    "once": true
                },
                {
                    "freqs": [
                        414
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            3000
        ]
    },
    "in": {
        "description": "India",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 750
                }
            ],
            "ring": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 7500
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            400,
            200,
            400,
            2000
        ]
    },
    "it": {
        "description": "Italy",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 600
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 14000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        470
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        470
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "lt": {
        "description": "Lithuania",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 350
                },
                {
                    "freqs": [],
                    "durationMs": 350
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 150
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 50
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "jp": {
        "description": "Japan",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        400,
                        15
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400,
                        16
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 8000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            2000
        ]
    },
    "mx": {
        "description": "Mexico",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 600
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 30
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            2000,
            4000
        ]
    },
    "my": {
        "description": "Malaysia",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            2000,
            4000
        ]
    },
    "nl": {
        "description": "Netherlands",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 9500
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 50
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 50
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "no": {
        "description": "Norway",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 600
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        470
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        470
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "nz": {
        "description": "New Zealand",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 3000,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 3000,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 3000,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 425
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 400
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "unobtainable": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 75
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 75
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 75
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 75
                },
                {
                    "freqs": [],
                    "durationMs": 400
                }
            ]
        },
        "ringcadence": [
            400,
            200,
            400,
            2000
        ]
    },
    "ph": {
        "description": "Philippines",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        480,
                        620
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425,
                        480
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        480,
                        620
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "pl": {
        "description": "Poland",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 150
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 150
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 50
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000,
                    "once": true
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "pt": {
        "description": "Portugal",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 5000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 200
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            5000
        ]
    },
    "ru": {
        "description": "Russian Federation / ex Soviet Union",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 350
                },
                {
                    "freqs": [],
                    "durationMs": 350
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 175
                },
                {
                    "freqs": [],
                    "durationMs": 175
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 5000
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 40
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "se": {
        "description": "Sweden",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 5000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 750
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 500
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 9100
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 2024,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 2024,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 2024,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 2024,
                    "once": true
                },
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 24,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 332,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            5000
        ]
    },
    "sg": {
        "description": "Singapore",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [],
                    "durationMs": 750
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 3200
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 2500
                },
                {
                    "freqs": [],
                    "durationMs": 0
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "nutone": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 2500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "intrusion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "warning": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 624
                },
                {
                    "freqs": [],
                    "durationMs": 4376
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "acceptance": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 125
                },
                {
                    "freqs": [],
                    "durationMs": 125
                }
            ],
            "holdinga": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 500,
                    "once": true
                }
            ],
            "holdingb": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 2500,
                    "once": true
                }
            ]
        },
        "ringcadence": [
            400,
            200,
            400,
            2000
        ]
    },
    "th": {
        "description": "Thailand",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 5000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 300
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        1000
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        10000
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        1000
                    ],
                    "durationMs": 400
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 100
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 100
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 600,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 200,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "uk": {
        "description": "United Kingdom",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "specialdial": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 750
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 375
                },
                {
                    "freqs": [],
                    "durationMs": 375
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 350
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 225
                },
                {
                    "freqs": [],
                    "durationMs": 525
                }
            ],
            "specialcongestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        1004
                    ],
                    "durationMs": 300
                }
            ],
            "unobtainable": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "ring": [
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "specialcallwaiting": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 5000
                }
            ],
            "creditexpired": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 125
                },
                {
                    "freqs": [],
                    "durationMs": 125
                }
            ],
            "confirm": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 0
                }
            ],
            "switching": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 200
                },
                {
                    "freqs": [],
                    "durationMs": 400
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 2000
                },
                {
                    "freqs": [],
                    "durationMs": 400
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 15
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 15
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 60000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 750
                },
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 750
                }
            ]
        },
        "ringcadence": [
            400,
            200,
            400,
            2000
        ]
    },
    "us": {
        "description": "United States Circa 1950/ North America",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        500
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        420
                    ],
                    "durationMs": 2000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        500
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        440
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        600
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            2000,
            4000
        ]
    },
    "tw": {
        "description": "Taiwan",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        480,
                        620
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        440,
                        480
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        480,
                        620
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 3250
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        300
                    ],
                    "durationMs": 1500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 0
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "ve": {
        "description": "Venezuela / South America",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "busy": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "ring": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 1000
                },
                {
                    "freqs": [],
                    "durationMs": 4000
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400,
                        450
                    ],
                    "durationMs": 300
                },
                {
                    "freqs": [],
                    "durationMs": 6000
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 15000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1440
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 1000
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        425
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            1000,
            4000
        ]
    },
    "za": {
        "description": "South Africa",
        "tones": {
            "dial": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ],
            "ring": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 200
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 400
                },
                {
                    "freqs": [],
                    "durationMs": 2000
                }
            ],
            "callwaiting": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "congestion": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 250
                },
                {
                    "freqs": [],
                    "durationMs": 250
                }
            ],
            "busy": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 500
                }
            ],
            "dialrecall": [
                {
                    "freqs": [
                        350,
                        440
                    ],
                    "durationMs": 0
                }
            ],
            "record": [
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 500
                },
                {
                    "freqs": [],
                    "durationMs": 10000
                }
            ],
            "info": [
                {
                    "freqs": [
                        950
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1400
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [
                        1800
                    ],
                    "durationMs": 330
                },
                {
                    "freqs": [],
                    "durationMs": 330
                }
            ],
            "stutter": [
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [],
                    "durationMs": 100,
                    "once": true
                },
                {
                    "freqs": [
                        400
                    ],
                    "durationMs": 0
                }
            ]
        },
        "ringcadence": [
            400,
            200,
            400,
            2000
        ]
    }
};

export function getToneSet(country: string): ToneSet {
  const code = country.toLowerCase().trim();
  return TONE_INDICATIONS[code] ?? TONE_INDICATIONS[DEFAULT_COUNTRY];
}

export function getToneSequence(country: string, toneName: string): ToneElement[] | null {
  return getToneSet(country).tones[toneName] ?? null;
}