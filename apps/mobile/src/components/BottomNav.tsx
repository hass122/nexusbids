import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Platform } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../theme/colors';
import { useAuth } from '../context/AuthContext';

export type BuyerTab = 'home' | 'auctions' | 'watchlist' | 'bids' | 'orders' | 'profile';
export type SellerTab = 'dashboard' | 'listings' | 'create' | 'orders' | 'payouts';

interface BottomNavProps {
  buyerTab: BuyerTab;
  onSelectBuyerTab: (tab: BuyerTab) => void;
  sellerTab: SellerTab;
  onSelectSellerTab: (tab: SellerTab) => void;
}

export const BottomNav: React.FC<BottomNavProps> = ({
  buyerTab,
  onSelectBuyerTab,
  sellerTab,
  onSelectSellerTab,
}) => {
  const { activeRole } = useAuth();

  // Seller Navigation (Studio, My Lots, Create, Fulfill, Payouts)
  if (activeRole === 'seller') {
    const tabs: {
      key: SellerTab;
      label: string;
      activeIcon: keyof typeof Ionicons.glyphMap;
      inactiveIcon: keyof typeof Ionicons.glyphMap;
      isFab?: boolean;
    }[] = [
      { key: 'dashboard', label: 'Studio', activeIcon: 'stats-chart', inactiveIcon: 'stats-chart-outline' },
      { key: 'listings', label: 'My Lots', activeIcon: 'pricetags', inactiveIcon: 'pricetags-outline' },
      { key: 'create', label: 'Create', activeIcon: 'add', inactiveIcon: 'add', isFab: true },
      { key: 'orders', label: 'Fulfill', activeIcon: 'car-sport', inactiveIcon: 'car-sport-outline' },
      { key: 'payouts', label: 'Payouts', activeIcon: 'wallet', inactiveIcon: 'wallet-outline' },
    ];

    return (
      <View style={styles.floatingWrapper}>
        <View style={styles.container}>
          {tabs.map((t) => {
            const isActive = sellerTab === t.key;

            if (t.isFab) {
              return (
                <TouchableOpacity
                  key={t.key}
                  style={styles.fabContainer}
                  onPress={() => onSelectSellerTab(t.key)}
                  activeOpacity={0.85}
                  accessibilityRole="button"
                  accessibilityLabel="Create Auction"
                >
                  <View style={styles.fabButton}>
                    <Ionicons name="add" size={24} color="#ffffff" />
                  </View>
                  <Text style={styles.fabLabel}>Create</Text>
                </TouchableOpacity>
              );
            }

            return (
              <TouchableOpacity
                key={t.key}
                style={[styles.tab, isActive && styles.activeTab]}
                onPress={() => onSelectSellerTab(t.key)}
                activeOpacity={0.7}
                accessibilityRole="tab"
                accessibilityState={{ selected: isActive }}
                accessibilityLabel={t.label}
              >
                <Ionicons
                  name={isActive ? t.activeIcon : t.inactiveIcon}
                  size={20}
                  color={isActive ? Colors.primaryLight : Colors.textMuted}
                />
                <Text
                  style={[styles.tabLabel, isActive && styles.activeLabel]}
                  numberOfLines={1}
                >
                  {t.label}
                </Text>
              </TouchableOpacity>
            );
          })}
        </View>
      </View>
    );
  }

  // Buyer Navigation: 6 items strictly preserved in order:
  // 1. Home, 2. Explore, 3. Saved, 4. Bids, 5. Orders, 6. Profile
  const buyerTabs: {
    key: BuyerTab;
    label: string;
    activeIcon: keyof typeof Ionicons.glyphMap;
    inactiveIcon: keyof typeof Ionicons.glyphMap;
  }[] = [
    { key: 'home', label: 'Home', activeIcon: 'home', inactiveIcon: 'home-outline' },
    { key: 'auctions', label: 'Explore', activeIcon: 'hammer', inactiveIcon: 'hammer-outline' },
    { key: 'watchlist', label: 'Saved', activeIcon: 'heart', inactiveIcon: 'heart-outline' },
    { key: 'bids', label: 'Bids', activeIcon: 'trophy', inactiveIcon: 'trophy-outline' },
    { key: 'orders', label: 'Orders', activeIcon: 'cube', inactiveIcon: 'cube-outline' },
    { key: 'profile', label: 'Profile', activeIcon: 'person', inactiveIcon: 'person-outline' },
  ];

  return (
    <View style={styles.floatingWrapper}>
      <View style={styles.container}>
        {buyerTabs.map((t) => {
          const isActive = buyerTab === t.key;
          return (
            <TouchableOpacity
              key={t.key}
              style={[styles.tab, isActive && styles.activeTab]}
              onPress={() => onSelectBuyerTab(t.key)}
              activeOpacity={0.7}
              accessibilityRole="tab"
              accessibilityState={{ selected: isActive }}
              accessibilityLabel={t.label}
            >
              <Ionicons
                name={isActive ? t.activeIcon : t.inactiveIcon}
                size={20}
                color={isActive ? Colors.primaryLight : Colors.textMuted}
              />
              <Text
                style={[styles.tabLabel, isActive && styles.activeLabel]}
                numberOfLines={1}
              >
                {t.label}
              </Text>
            </TouchableOpacity>
          );
        })}
      </View>
    </View>
  );
};

const styles = StyleSheet.create({
  floatingWrapper: {
    backgroundColor: 'transparent',
    paddingHorizontal: 12,
    paddingTop: 4,
    paddingBottom: Platform.OS === 'ios' ? 6 : 10,
  },
  container: {
    flexDirection: 'row',
    backgroundColor: '#0B1120', // Deep navy surface
    borderRadius: 22,
    borderWidth: 1,
    borderColor: Colors.border, // #1E293B subtle border
    paddingVertical: 5,
    paddingHorizontal: 4,
    justifyContent: 'space-between',
    alignItems: 'center',
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.35,
    shadowRadius: 10,
    elevation: 8,
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 5,
    paddingHorizontal: 2,
    borderRadius: 14,
    marginHorizontal: 1,
    minHeight: 46,
  },
  activeTab: {
    backgroundColor: 'rgba(81, 69, 229, 0.18)', // Subtle rounded active purple background
  },
  tabLabel: {
    fontSize: 10,
    fontWeight: '600',
    color: Colors.textMuted, // Muted grey-blue #A7B0C3
    marginTop: 2.5,
    letterSpacing: -0.2,
  },
  activeLabel: {
    color: Colors.primaryLight, // Purple/violet #7952F5
    fontWeight: '700',
  },
  fabContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    top: -6,
  },
  fabButton: {
    width: 42,
    height: 42,
    borderRadius: 21,
    backgroundColor: Colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.3,
    shadowRadius: 6,
    elevation: 5,
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.3)',
  },
  fabLabel: {
    fontSize: 10,
    fontWeight: '700',
    color: Colors.primary,
    marginTop: 2,
  },
});
