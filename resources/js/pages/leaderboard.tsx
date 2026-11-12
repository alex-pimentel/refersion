import { Head } from '@inertiajs/react';

export default function Leaderboard() {
    return (
        <>
            <Head title="Top Affiliates" />
            <main className="flex min-h-screen items-center justify-center bg-[#F2F6EF] p-8 text-[#221F4E]">
                <div className="rounded-2xl border border-[#DED7D4] bg-white p-10 shadow-sm">
                    <p className="text-sm font-semibold tracking-[0.2em] uppercase">
                        Refersion
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold">
                        Affiliate leaderboard
                    </h1>
                    <p className="mt-3 text-[#53677D]">
                        The interface is being wired to the Refersion API.
                    </p>
                </div>
            </main>
        </>
    );
}
