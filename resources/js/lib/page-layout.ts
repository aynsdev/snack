export function usesSettingsLayout(pageName: string): boolean {
    return (
        pageName.startsWith('settings/') ||
        pageName === 'notifications/preferences'
    );
}
