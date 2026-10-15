import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
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

  // Seller Navigation
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
            >
              <Ionicons
                name={isActive ? t.activeIcon : t.inactiveIcon}
                size={21}
                color={isActive ? Colors.primaryLight : Colors.textDim}
              />
              <Text style={[styles.tabLabel, isActive && styles.activeLabel]}>
                {t.label}
              </Text>
              {isActive && <View style={styles.activeIndicator} />}
            </TouchableOpacity>
          );
        })}
      </View>
    );
  }

  // Buyer Navigation
  const buyerTabs: {
    key: BuyerTab;
    label: string;
    activeIcon: keyof typeof Ionicons.glyphMap;
    inactiveIcon: keyof typeof Ionicons.glyphMap;
  }[] = [
    { key: 'home', label: 'Home', activeIcon: 'flash', inactiveIcon: 'flash-outline' },
    { key: 'auctions', label: 'Explore', activeIcon: 'hammer', inactiveIcon: 'hammer-outline' },
    { key: 'watchlist', label: 'Saved', activeIcon: 'heart', inactiveIcon: 'heart-outline' },
    { key: 'bids', label: 'Bids', activeIcon: 'trophy', inactiveIcon: 'trophy-outline' },
    { key: 'orders', label: 'Orders', activeIcon: 'cube', inactiveIcon: 'cube-outline' },
    { key: 'profile', label: 'Profile', activeIcon: 'person', inactiveIcon: 'person-outline' },
  ];

  return (
    <View style={styles.container}>
      {buyerTabs.map((t) => {
        const isActive = buyerTab === t.key;
        return (
          <TouchableOpacity
            key={t.key}
            style={[styles.tab, isActive && styles.activeTab]}
            onPress={() => onSelectBuyerTab(t.key)}
            activeOpacity={0.7}
          >
            <Ionicons
              name={isActive ? t.activeIcon : t.inactiveIcon}
              size={20}
              color={isActive ? Colors.primaryLight : Colors.textDim}
            />
            <Text style={[styles.tabLabel, isActive && styles.activeLabel]}>
              {t.label}
            </Text>
            {isActive && <View style={styles.activeIndicator} />}
          </TouchableOpacity>
        );
      })}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    backgroundColor: Colors.surface,
    borderTopWidth: 1,
    borderTopColor: Colors.borderSubtle,
    paddingTop: 8,
    paddingBottom: 22,
    justifyContent: 'space-around',
    alignItems: 'center',
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: -3 },
    shadowOpacity: 0.25,
    shadowRadius: 6,
    elevation: 8,
  },
  tab: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 8,
    paddingVertical: 4,
    minWidth: 48,
  },
  activeTab: {
    transform: [{ scale: 1.02 }],
  },
  tabLabel: {
    fontSize: 10,
    fontWeight: '600',
    color: Colors.textDim,
    marginTop: 3,
    letterSpacing: -0.2,
  },
  activeLabel: {
    color: Colors.primaryLight,
    fontWeight: '800',
  },
  activeIndicator: {
    width: 14,
    height: 2.5,
    borderRadius: 1.5,
    backgroundColor: Colors.primary,
    marginTop: 3,
  },
  fabContainer: {
    alignItems: 'center',
    justifyContent: 'center',
    top: -8,
  },
  fabButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: Colors.primaryDark,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.45,
    shadowRadius: 8,
    elevation: 6,
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.2)',
  },
  fabLabel: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.primaryLight,
    marginTop: 3,
  },
});
