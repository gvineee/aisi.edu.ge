import type { Metadata } from 'next';
import './globals.css';
export const metadata: Metadata = {title:'აისი — ახალი დღის დასაწყისი',description:'სკოლის ვებგვერდისა და პორტალის დიზაინის კონცეფცია',robots:{index:false,follow:false}};
export default function RootLayout({children}:Readonly<{children:React.ReactNode}>){return <html lang="ka"><body>{children}</body></html>}
