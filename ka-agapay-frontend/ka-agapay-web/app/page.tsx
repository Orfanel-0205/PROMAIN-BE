// app/page.tsx
'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import Cookies from 'js-cookie';
import { Spin } from 'antd';

export default function HomePage() {
    const router = useRouter();

    useEffect(() => {
        const token = Cookies.get('ka_agapay_token');
        if (token) {
            router.push('/dashboard');
        } else {
            router.push('/login');
        }
    }, [router]);

    return (
        <div className="min-h-screen flex items-center justify-center">
            <Spin size="large" tip="Loading Ka-agapay..." />
        </div>
    );
}