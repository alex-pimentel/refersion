export type LeaderboardEntry = {
    rank: number;
    affiliateId: number;
    name: string;
    email: string;
    commissionCents: number;
    currency: string;
};

export type LeaderboardMeta = {
    generatedAt: string;
    cached: boolean;
    totalAffiliates: number;
    scannedAffiliates: number;
    eligibleCount: number;
    customFieldRequests: number;
    durationMs: number;
};

export type LeaderboardError = {
    message: string;
    reference: string;
};

export type LeaderboardPageData = {
    data: LeaderboardEntry[];
    meta: LeaderboardMeta | null;
    error: LeaderboardError | null;
};
