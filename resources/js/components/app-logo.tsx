export default function AppLogo() {
    return (
        <>
            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/95 p-0.5 shadow-sm ring-1 ring-white/20 group-data-[collapsible=icon]:size-8">
                <img
                    src="/images/zcmc.png"
                    alt="Zamboanga City Medical Center seal"
                    className="size-full object-contain"
                />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <span className="truncate text-sm font-bold tracking-wide">
                    ZCMC
                </span>
                <span className="truncate text-[11px] font-medium text-sidebar-primary">
                    Material Management
                </span>
            </div>
        </>
    );
}
