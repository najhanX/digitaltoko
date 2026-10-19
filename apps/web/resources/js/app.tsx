import ScrollbarTheme from './components/ScrollbarTheme';
import SandboxIndicator from './components/SandboxIndicator';
import { createInertiaApp } from '@inertiajs/react';
import { PanelNotificationProvider, type NotificationSummary, type PanelNotice } from './components/PanelNotifications';

createInertiaApp({ title: (title) => `${title} · DigitalToko`, strictMode: true,
    withApp: (app,{page}) => <PanelNotificationProvider initialSummary={page.props.notificationSummary as NotificationSummary|null|undefined} initialNotice={page.flash?.panelNotice as PanelNotice|undefined}><ScrollbarTheme initial={page}/><SandboxIndicator initial={Boolean(page.props.storeSandbox)}/>{app}</PanelNotificationProvider>,
});
