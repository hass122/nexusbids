import React, { useState, useEffect } from 'react';
import * as SplashScreen from 'expo-splash-screen';
import * as Font from 'expo-font';
import { Ionicons } from '@expo/vector-icons';
import { StyleSheet, View, SafeAreaView, StatusBar } from 'react-native';
import { Colors } from './src/theme/colors';
import { AuthProvider, useAuth } from './src/context/AuthContext';
import { Header } from './src/components/Header';
import { BottomNav, BuyerTab, SellerTab } from './src/components/BottomNav';
import { PushNotificationBanner } from './src/components/PushNotificationBanner';
import { OfflineBanner } from './src/components/OfflineBanner';
import { NotificationService } from './src/services/notificationService';

// Buyer Screens
import {
  HomeScreen,
  AuctionsScreen,
  WatchlistScreen,
  BidsScreen,
  OrdersScreen,
  ProfileScreen,
} from './src/screens/buyer/BuyerViews';

// Seller Screens & Modals
import {
  SellerDashboardScreen,
  SellerListingsScreen,
  CreateAuctionModal,
  EditAuctionModal,
  SellerAnalyticsModal,
  SellerOrdersScreen,
  ShippingModal,
  SellerPayoutsScreen,
  SellerKycModal,
  SellerReviewsScreen,
} from './src/screens/seller/SellerViews';

// Interactive Modals
import { AuthModal } from './src/screens/auth/AuthModal';
import { AuctionDetailModal } from './src/screens/buyer/AuctionDetailModal';
import { OrderDetailModal } from './src/screens/buyer/OrderDetailModal';
import { CheckoutModal } from './src/screens/buyer/CheckoutModal';
import {
  BuyerKycModal,
  NotificationsModal,
  AddressesModal,
  SecurityModal,
  ReviewsModal,
} from './src/screens/buyer/BuyerModals';
import { AiChatModal } from './src/screens/ai/AiChatModal';
import { MobileApi } from './src/api/client';

function MainNavigator() {
  const { user, activeRole } = useAuth();

  // Tab states
  const [buyerTab, setBuyerTab] = useState<BuyerTab>('home');
  const [sellerTab, setSellerTab] = useState<SellerTab>('dashboard');
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null);

  // Modal visibility states
  const [authModalVisible, setAuthModalVisible] = useState(false);
  const [selectedAuctionId, setSelectedAuctionId] = useState<number | null>(null);
  const [selectedOrderDetailId, setSelectedOrderDetailId] = useState<number | null>(null);
  const [selectedCheckoutOrderId, setSelectedCheckoutOrderId] = useState<number | null>(null);
  const [createModalVisible, setCreateModalVisible] = useState(false);
  const [editingAuction, setEditingAuction] = useState<any | null>(null);
  const [analyticsVisible, setAnalyticsVisible] = useState(false);
  const [shippingOrder, setShippingOrder] = useState<any | null>(null);
  const [buyerKycVisible, setBuyerKycVisible] = useState(false);
  const [sellerKycVisible, setSellerKycVisible] = useState(false);
  const [notifModalVisible, setNotifModalVisible] = useState(false);
  const [addrModalVisible, setAddrModalVisible] = useState(false);
  const [securityModalVisible, setSecurityModalVisible] = useState(false);
  const [reviewsModalVisible, setReviewsModalVisible] = useState(false);
  const [reviewTarget, setReviewTarget] = useState<{ sellerId: number; orderId: number } | null>(null);
  const [aiChatVisible, setAiChatVisible] = useState(false);

  // Network & notification badge state
  const [isOffline, setIsOffline] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);

  // Load unread count periodically
  useEffect(() => {
    const fetchUnread = async () => {
      if (!user) {
        setUnreadCount(NotificationService.getUnreadCount());
        return;
      }
      try {
        const res = await MobileApi.getUnreadNotifCount();
        const serverCount = res.data?.count || 0;
        setUnreadCount(Math.max(serverCount, NotificationService.getUnreadCount()));
        setIsOffline(false);
      } catch {
        setUnreadCount(NotificationService.getUnreadCount());
      }
    };

    fetchUnread();
    const interval = setInterval(fetchUnread, 12000);
    return () => clearInterval(interval);
  }, [user]);

  const handleNotificationPress = (notif: any) => {
    if (notif.auctionId) {
      setSelectedAuctionId(notif.auctionId);
    } else if (notif.orderId) {
      setSelectedOrderDetailId(notif.orderId);
    } else {
      setNotifModalVisible(true);
    }
  };

  return (
    <SafeAreaView style={styles.container}>
      <StatusBar barStyle="light-content" backgroundColor={Colors.background} />

      {/* Push Notification Toast Banner */}
      <PushNotificationBanner onPressNotification={handleNotificationPress} />

      {/* Offline Mode Banner */}
      <OfflineBanner isOffline={isOffline} />

      {/* Persistent Brand Header */}
      <Header
        onNotificationPress={() => setNotifModalVisible(true)}
        onProfilePress={() => {
          if (!user) {
            setAuthModalVisible(true);
          } else {
            setBuyerTab('profile');
          }
        }}
        unreadCount={unreadCount}
      />

      {/* Screen Body */}
      <View style={styles.body}>
        {activeRole === 'buyer' ? (
          <>
            {buyerTab === 'home' && (
              <HomeScreen
                onSelectAuction={(id) => setSelectedAuctionId(id)}
                onExploreMore={(catId) => {
                  if (catId) setSelectedCategoryId(catId);
                  setBuyerTab('auctions');
                }}
                onOpenChat={() => setAiChatVisible(true)}
              />
            )}
            {buyerTab === 'auctions' && (
              <AuctionsScreen
                onSelectAuction={(id) => setSelectedAuctionId(id)}
                selectedCategoryId={selectedCategoryId}
              />
            )}
            {buyerTab === 'watchlist' && (
              <WatchlistScreen
                onSelectAuction={(id) => setSelectedAuctionId(id)}
                onExploreMore={() => setBuyerTab('auctions')}
              />
            )}
            {buyerTab === 'bids' && (
              <BidsScreen
                onSelectAuction={(id) => setSelectedAuctionId(id)}
                onCheckout={(orderId) => setSelectedCheckoutOrderId(orderId)}
              />
            )}
            {buyerTab === 'orders' && (
              <OrdersScreen
                onSelectOrder={(orderId) => setSelectedOrderDetailId(orderId)}
              />
            )}
            {buyerTab === 'profile' && (
              <ProfileScreen
                onOpenKyc={() => setBuyerKycVisible(true)}
                onOpenAddresses={() => setAddrModalVisible(true)}
                onOpenSecurity={() => setSecurityModalVisible(true)}
                onOpenReviews={() => setReviewsModalVisible(true)}
              />
            )}
          </>
        ) : (
          <>
            {sellerTab === 'dashboard' && (
              <SellerDashboardScreen
                onOpenCreate={() => setCreateModalVisible(true)}
                onGoToPayouts={() => setSellerTab('payouts')}
                onGoToOrders={() => setSellerTab('orders')}
                onOpenAnalytics={() => setAnalyticsVisible(true)}
                onOpenSellerKyc={() => setSellerKycVisible(true)}
              />
            )}
            {sellerTab === 'listings' && (
              <SellerListingsScreen
                onOpenCreate={() => setCreateModalVisible(true)}
                onEditAuction={(auction) => setEditingAuction(auction)}
              />
            )}
            {sellerTab === 'create' && (
              <SellerDashboardScreen
                onOpenCreate={() => setCreateModalVisible(true)}
                onGoToPayouts={() => setSellerTab('payouts')}
                onGoToOrders={() => setSellerTab('orders')}
                onOpenAnalytics={() => setAnalyticsVisible(true)}
                onOpenSellerKyc={() => setSellerKycVisible(true)}
              />
            )}
            {sellerTab === 'orders' && (
              <SellerOrdersScreen
                onOpenShipment={(order) => setShippingOrder(order)}
              />
            )}
            {sellerTab === 'payouts' && <SellerPayoutsScreen />}
          </>
        )}
      </View>

      {/* Bottom Navigation */}
      <BottomNav
        buyerTab={buyerTab}
        onSelectBuyerTab={setBuyerTab}
        sellerTab={sellerTab}
        onSelectSellerTab={(tab) => {
          if (tab === 'create') {
            setCreateModalVisible(true);
          } else {
            setSellerTab(tab);
          }
        }}
      />

      {/* Modals Suite */}
      <AuthModal
        visible={authModalVisible}
        onClose={() => setAuthModalVisible(false)}
      />

      <AuctionDetailModal
        visible={selectedAuctionId !== null}
        auctionId={selectedAuctionId}
        onClose={() => setSelectedAuctionId(null)}
      />

      <OrderDetailModal
        visible={selectedOrderDetailId !== null}
        orderId={selectedOrderDetailId}
        onClose={() => setSelectedOrderDetailId(null)}
        onOpenReview={(sellerId, orderId) => {
          setSelectedOrderDetailId(null);
          setReviewTarget({ sellerId, orderId });
          setReviewsModalVisible(true);
        }}
      />

      <CheckoutModal
        visible={selectedCheckoutOrderId !== null}
        orderId={selectedCheckoutOrderId}
        onClose={() => setSelectedCheckoutOrderId(null)}
        onPaymentSuccess={() => {
          setSelectedCheckoutOrderId(null);
          setBuyerTab('orders');
        }}
      />

      <CreateAuctionModal
        visible={createModalVisible}
        onClose={() => setCreateModalVisible(false)}
        onSuccess={() => setSellerTab('listings')}
      />

      <EditAuctionModal
        visible={editingAuction !== null}
        auction={editingAuction}
        onClose={() => setEditingAuction(null)}
        onSuccess={() => setSellerTab('listings')}
      />

      <SellerAnalyticsModal
        visible={analyticsVisible}
        onClose={() => setAnalyticsVisible(false)}
      />

      <ShippingModal
        visible={shippingOrder !== null}
        order={shippingOrder}
        onClose={() => setShippingOrder(null)}
        onSuccess={() => setSellerTab('orders')}
      />

      <BuyerKycModal
        visible={buyerKycVisible}
        onClose={() => setBuyerKycVisible(false)}
      />

      <SellerKycModal
        visible={sellerKycVisible}
        onClose={() => setSellerKycVisible(false)}
      />

      <NotificationsModal
        visible={notifModalVisible}
        onClose={() => {
          setNotifModalVisible(false);
          setUnreadCount(0);
        }}
      />

      <AddressesModal
        visible={addrModalVisible}
        onClose={() => setAddrModalVisible(false)}
      />

      <SecurityModal
        visible={securityModalVisible}
        onClose={() => setSecurityModalVisible(false)}
      />

      <ReviewsModal
        visible={reviewsModalVisible}
        sellerId={reviewTarget?.sellerId}
        orderId={reviewTarget?.orderId}
        onClose={() => {
          setReviewsModalVisible(false);
          setReviewTarget(null);
        }}
      />

      <AiChatModal
        visible={aiChatVisible}
        onClose={() => setAiChatVisible(false)}
      />
    </SafeAreaView>
  );
}

// Keep splash screen visible while loading resources
SplashScreen.preventAutoHideAsync().catch(() => { });

export default function App() {
  const [appIsReady, setAppIsReady] = useState(false);

  useEffect(() => {
    async function prepare() {
      try {
        // Explicitly load Ionicons font definitions to prevent fontFamily warning
        await Font.loadAsync({
          ...Ionicons.font,
        });
      } catch (err) {
        console.warn('Ionicons font loading error:', err);
      } finally {
        setAppIsReady(true);
        SplashScreen.hideAsync().catch(() => { });
      }
    }

    prepare();
  }, []);

  if (!appIsReady) {
    return null;
  }

  return (
    <AuthProvider>
      <MainNavigator />
    </AuthProvider>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: Colors.background,
  },
  body: {
    flex: 1,
  },
});
