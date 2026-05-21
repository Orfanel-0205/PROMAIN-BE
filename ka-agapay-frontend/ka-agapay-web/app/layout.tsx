// app/layout.tsx
import type { Metadata } from 'next';
import { AntdRegistry } from '@ant-design/nextjs-registry';
import './globals.css';
import KaAgapayChat from '@/components/chatbot/KaAgapayChat';


export const metadata: Metadata = {
  title: 'Ka-agapay Admin Portal',
  description: 'RHU1 & RHU2 Malasiqui Health Service Hub',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body>
        <AntdRegistry>
          {children}
          <KaAgapayChat />
        </AntdRegistry>
      </body>

    </html>
  );
}